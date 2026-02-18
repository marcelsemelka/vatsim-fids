<?php
// vFIDS_worker.php - Robust Overpass Fetcher with JSON Validation & Direct Logging

// --- KONFIGURATION ---
define('ENABLE_FILE_LOGGING', false); // Setze auf true für zusätzliches Datei-Log
// ---------------------

if (php_sapi_name() !== 'cli') die("CLI only");

// Pufferung abschalten für Echtzeit-Logs
@ob_end_flush();
ob_implicit_flush(true);

$logFile = __DIR__ . '/worker_debug.log';

function wlog($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $msg\n";

    // 1. In Datei schreiben (optional)
    if (defined('ENABLE_FILE_LOGGING') && ENABLE_FILE_LOGGING === true) {
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }

    // 2. Direkt in den System-Output streamen (für tail -f / Cron logs)
    fwrite(STDOUT, $entry);
}

// Helper: Ist der String valides JSON?
function is_json($string) {
    if (!is_string($string)) return false;
    json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
}

ini_set('memory_limit', '1024M');
set_time_limit(0);

$db_host = ''; $db_user = ''; $db_pass = ''; $db_name = 'vFIDS';
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    fwrite(STDERR, "DB Connection failed: " . $conn->connect_error . "\n");
    exit(1);
}
$conn->set_charset("utf8mb4");

wlog("WORKER START (Secure Mode)");

$start = time();
$maxRun = 55; // Sekunden Laufzeit pro Cron-Aufruf
$jobsProcessed = 0;

while ((time() - $start) < $maxRun) {
    // DB Ping um "MySQL server has gone away" bei langen Pausen zu verhindern
    if (!$conn->ping()) {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $conn->set_charset("utf8mb4");
    }

    $res = $conn->query("SELECT * FROM vFIDS_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");

    if (!$res || $res->num_rows === 0) {
        usleep(1000000); // 1.0 Sekunden Pause
        continue;
    }

    $job = $res->fetch_assoc();
    $hash = $job['query_hash'];
    $rawText = $job['query_text'];
    $jobsProcessed++;

    // --- DECODING LOGIC ---
    // Wir erwarten jetzt "B64:" Präfix von der neuen API
    $overpassQuery = $rawText;
    if (strpos($rawText, 'B64:') === 0) {
        $b64 = substr($rawText, 4);
        $decoded = base64_decode($b64);
        if ($decoded) {
            $overpassQuery = $decoded;
            wlog("Job $hash: Base64 detected & decoded.");
        } else {
            wlog("Job $hash: Base64 decode ERROR! Deleting job.");
            $conn->query("DELETE FROM vFIDS_queue WHERE query_hash = '$hash'");
            continue;
        }
    } else {
        wlog("Job $hash: Plaintext query detected.");
    }
    // -----------------------

    // Status auf processing setzen
    $conn->query("UPDATE vFIDS_queue SET status = 'processing' WHERE query_hash = '$hash'");

    $servers = [
        "https://lz4.overpass-api.de/api/interpreter",
        "https://overpass-api.de/api/interpreter",
        "https://overpass.private.coffee/api/interpreter",
        "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
        "http://overpass.openstreetmap.fr/api/interpreter"
    ];
    shuffle($servers);

    $success = false;
    // Overpass API erwartet Query im POST-Body "data="
    $postData = http_build_query(['data' => $overpassQuery]);

    foreach ($servers as $server) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $server,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, // Schneller Fail bei Verbindungsproblemen
            CURLOPT_TIMEOUT => 60,       // Genug Zeit für große OSM Daten
            CURLOPT_USERAGENT => "vFIDS-Worker/4.0 (Secure)",
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // CHECK: HTTP 200 UND valides JSON
        if ($code === 200 && !empty($resp)) {
            // Check 1: Sieht es aus wie JSON? (Schnelltest)
            $trimmed = trim($resp);
            if (substr($trimmed, 0, 1) === '{' && is_json($resp)) {

                // Check 2: Overpass Runtime Error im JSON?
                if (stripos($resp, '"remark":') !== false && stripos($resp, 'runtime error') !== false) {
                     wlog("  -> Failed ($server): Valid JSON but contains Overpass Runtime Error.");
                     continue;
                }

                wlog("  -> SUCCESS ($server) - Size: " . strlen($resp) . " bytes");

                // Speichern
                $valEsc = $conn->real_escape_string($resp);
                $conn->query("INSERT INTO vFIDS_osm (cache_key, cache_value) VALUES ('vatsimFids_OSM_$hash', '$valEsc') ON DUPLICATE KEY UPDATE cache_value = '$valEsc', updated_at = NOW()");

                // Job löschen
                $conn->query("DELETE FROM vFIDS_queue WHERE query_hash = '$hash'");

                $success = true;
                break; // Erfolg -> Raus aus der Server-Schleife
            } else {
                $preview = substr(strip_tags($resp), 0, 50);
                wlog("  -> Failed ($server): HTTP 200 but INVALID JSON. Start: '$preview'...");
            }
        } else {
            wlog("  -> Failed ($server): HTTP $code / $err");
        }
    }

    if (!$success) {
        wlog("Job $hash failed on all servers. Deleting to prevent endless loop.");
        $conn->query("DELETE FROM vFIDS_queue WHERE query_hash = '$hash'");
    }

    // Kurze Pause vor dem nächsten Job um CPU zu schonen
    usleep(10000);
}

// Am Ende kurz berichten, wenn nichts passiert ist (hilft beim Debuggen)
if ($jobsProcessed === 0) {
    // Optional: Einkommentieren, wenn du jede Minute "Idle" sehen willst
    // wlog("Worker finished. Idle cycle.");
} else {
    wlog("Worker finished. Processed $jobsProcessed jobs.");
}

$conn->close();
?>
