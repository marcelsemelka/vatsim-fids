<?php
/**
 * VATSIM FIDS - Stats Worker (Multi-Lane with Failover/Boost)
 * * Logik:
 * 1. Lane DIRECT: Immer aktiv (außer bei 429)
 * 2. Lane PUBLIC: Immer aktiv (außer bei 429)
 * 3. Lane PRIVATE:
 * - Normal: 30% Wahrscheinlichkeit (Kosten sparen)
 * - Boost: 100% Wahrscheinlichkeit, wenn DIRECT oder PUBLIC ausgefallen sind (Geschwindigkeit erhalten)
 */

// --- KONFIGURATION ---
define('PROXY_PUBLIC', 'https://corsproxy.io/?url=');
define('PROXY_PRIVATE', '');
define('MY_ORIGIN', 'https://streamberlin.ddnss.de');

define('CHANCE_PRIVATE_NORMAL', 30); // 30% Nutzung im Normalbetrieb
define('COOLDOWN_SEC', 120);         // 2 Minuten Pause bei 429

define('API_BASE', 'https://api.vatsim.net/v2/members/');
define('USER_AGENT', "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36");

function console_log($msg) {
    echo "[" . date('H:i:s') . "] " . $msg . "\n";
}

// Hilfsfunktion: Prüft ob Lane offen ist
function isLaneAvailable($name) {
    $file = sys_get_temp_dir() . '/vfids_cool_' . $name . '.flag';
    if (file_exists($file)) {
        if (time() - filemtime($file) < COOLDOWN_SEC) {
            return false; // Noch im Cooldown
        } else {
            @unlink($file); // Cooldown abgelaufen
            return true;
        }
    }
    return true;
}

// Hilfsfunktion: Setzt Lane auf Cooldown
function triggerLaneCooldown($name) {
    $file = sys_get_temp_dir() . '/vfids_cool_' . $name . '.flag';
    touch($file);
    console_log("!!! LANE $name -> 429 DETECTED -> PAUSED FOR " . COOLDOWN_SEC . "s !!!");
}

// --- SETUP ---
$db_host = 'localhost'; $db_user = 'marcel'; $db_pass = 'elvn8*5kkZD*roT$OcUA'; $db_name = 'vFIDS';
ignore_user_abort(true);
set_time_limit(300);

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { console_log("FATAL: DB Error"); die(); }

// Single Instance Lock
$lockFile = sys_get_temp_dir() . '/vfids_stats_worker.lock';
$fp = @fopen($lockFile, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) die();

console_log("Worker gestartet (Failover Mode).");

$max_cycles = 10;
$cycles = 0;

while ($cycles < $max_cycles) {

    // 1. STATUS PRÜFEN
    $directOpen = isLaneAvailable('DIRECT');
    $publicOpen = isLaneAvailable('PUBLIC');
    $privateOpen = isLaneAvailable('PRIVATE');

    $availableLanes = [];

    // Lane 1: DIRECT
    if ($directOpen) $availableLanes[] = 'DIRECT';

    // Lane 2: PUBLIC
    if ($publicOpen) $availableLanes[] = 'PUBLIC';

    // Lane 3: PRIVATE (Intelligente Logik)
    if ($privateOpen) {
        $usePrivate = false;

        // Regel A: Normaler Zufall
        if (rand(1, 100) <= CHANCE_PRIVATE_NORMAL) {
            $usePrivate = true;
        }

        // Regel B: FAILOVER / BOOST
        // Wenn eine der Standard-Lanes tot ist, muss Private zu 100% ran!
        if (!$directOpen || !$publicOpen) {
            $usePrivate = true;
            // Kleines Log-Feedback (nur 1x pro Loop interessant, aber okay)
            if($cycles === 0) console_log(">> BOOST MODE: Private übernimmt Last von ausgefallener Lane.");
        }

        if ($usePrivate) {
            $availableLanes[] = 'PRIVATE';
        }
    }

    $limit = count($availableLanes);

    if ($limit === 0) {
        console_log("Alle Lanes im Cooldown. Warte...");
        sleep(5);
        $cycles++;
        continue;
    }

    // 2. Jobs holen
    $sql = "SELECT cid FROM vFIDS_stats_queue WHERE status = 'pending' AND created_at <= NOW() ORDER BY created_at ASC LIMIT $limit";
    $res = $conn->query($sql);

    if ($res->num_rows === 0) {
        console_log("Queue leer.");
        break;
    }

    $jobs = [];
    while($row = $res->fetch_assoc()) {
        $jobs[] = $row['cid'];
        $conn->query("UPDATE vFIDS_stats_queue SET status = 'processing' WHERE cid = " . $row['cid']);
    }

    // 3. cURL Multi Setup
    $mh = curl_multi_init();
    $handles = [];

    foreach ($jobs as $idx => $cid) {
        if (!isset($availableLanes[$idx])) break;

        $laneName = $availableLanes[$idx];
        $ch = curl_init();
        $fetchUrl = "";
        $headers = [
            "User-Agent: " . USER_AGENT,
            "Accept: application/json"
        ];

        if ($laneName === 'DIRECT') {
            $fetchUrl = API_BASE . $cid . "/stats";
        }
        elseif ($laneName === 'PUBLIC') {
            $target = API_BASE . $cid . "/stats";
            $fetchUrl = PROXY_PUBLIC . urlencode($target);
        }
        elseif ($laneName === 'PRIVATE') {
            $target = API_BASE . $cid . "/stats";
            $fetchUrl = PROXY_PRIVATE . urlencode($target);
            $headers[] = "Origin: " . MY_ORIGIN;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $fetchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HEADER => false
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$cid] = ['ch' => $ch, 'lane' => $laneName];
    }

    // 4. Ausführung
    $active = null;
    $timeStart = microtime(true);
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }
    $dur = round((microtime(true) - $timeStart) * 1000);
    console_log("Batch ($limit Lanes) fertig in {$dur}ms");

    // 5. Ergebnisse
    foreach ($handles as $cid => $meta) {
        $ch = $meta['ch'];
        $lane = $meta['lane'];
        $content = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if(curl_errno($ch)) {
            $httpCode = 0;
            console_log("[$lane] CID $cid -> cURL Error: " . curl_error($ch));
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        // Analyse
        $isVatsimNotFound = false;
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            if (isset($decoded['detail']) && $decoded['detail'] === 'Not Found') $isVatsimNotFound = true;
            if (isset($decoded['id']) && !$decoded['id']) $isVatsimNotFound = true;
        }

        console_log("[$lane] CID $cid -> HTTP $httpCode");

        if ($httpCode == 200 && $content && !$isVatsimNotFound) {
            $esc = $conn->real_escape_string($content);
            $conn->query("INSERT INTO vFIDS_members (cid, stats_json, updated_at) VALUES ($cid, '$esc', NOW())
                          ON DUPLICATE KEY UPDATE stats_json = '$esc', updated_at = NOW()");
            $conn->query("DELETE FROM vFIDS_stats_queue WHERE cid = $cid");
        }
        elseif ($httpCode == 404 || $isVatsimNotFound) {
            console_log(" -> Existiert nicht.");
            $conn->query("INSERT INTO vFIDS_members (cid, stats_json, updated_at) VALUES ($cid, 'null', NOW())
                          ON DUPLICATE KEY UPDATE stats_json = 'null', updated_at = NOW()");
            $conn->query("DELETE FROM vFIDS_stats_queue WHERE cid = $cid");
        }
        elseif ($httpCode == 429) {
            triggerLaneCooldown($lane);
            $conn->query("UPDATE vFIDS_stats_queue SET status = 'pending' WHERE cid = $cid");
        }
        else {
            console_log(" -> Retry später (Code $httpCode).");
            $conn->query("UPDATE vFIDS_stats_queue SET status = 'pending', created_at = DATE_ADD(NOW(), INTERVAL 3 MINUTE) WHERE cid = $cid");
        }
    }

    curl_multi_close($mh);
    $cycles++;

    // 6. Pause (10 Req/Min pro Lane)
    $sleep = rand(6000000, 7300000);
    usleep($sleep);
}

flock($fp, LOCK_UN);
fclose($fp);
$conn->close();
?>
