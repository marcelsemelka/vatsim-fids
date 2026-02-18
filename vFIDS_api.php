<?php
/**
 * VATSIM FIDS - API & Worker Trigger (SECURE VERSION)
 */

// --- KONFIGURATION ---
define('ENABLE_FILE_LOGGING', false); 

// Debug Logger
function dbg($msg) {
    if (!defined('ENABLE_FILE_LOGGING') || !ENABLE_FILE_LOGGING) return;
    $line = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    @file_put_contents(__DIR__ . '/debug_api.txt', $line, FILE_APPEND);
}

// 1. WordPress Auth Check
// HINWEIS: Wenn du später "Public" gehst, musst du diesen Block entfernen oder anpassen.
$possible_paths = [
    '/var/www/html/wordpress/wp-load.php',
    __DIR__ . '/../wordpress/wp-load.php',
    __DIR__ . '/wp-load.php',
    dirname(__DIR__) . '/wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'
];

$wp_loaded = false;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded || !is_user_logged_in()) {
    // Im Public Mode hier ggf. lockern, aktuell strikt:
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(["error" => "Unauthorized"]));
}

// 2. Datenbank Verbindung
$db_host = '';
$db_user = '';
$db_pass = '';
$db_name = 'vFIDS';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header("Access-Control-Allow-Origin: " . site_url());
header("Access-Control-Allow-Credentials: true");

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "DB Error"])); // Keine Details an Client senden
}
$conn->set_charset("utf8mb4");

// --- TABELLEN INITIALISIERUNG ---
$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_cache (
    cache_key VARCHAR(100) PRIMARY KEY,
    cache_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_queue (
    query_hash VARCHAR(32) PRIMARY KEY,
    query_text TEXT NOT NULL,
    status ENUM('pending', 'processing', 'done', 'error') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_osm (
    cache_key VARCHAR(100) PRIMARY KEY,
    cache_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_members (
    cid INT PRIMARY KEY,
    stats_json LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_stats_queue (
    cid INT PRIMARY KEY,
    status ENUM('pending', 'processing') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// =======================================================================
// 1. SCHREIBZUGRIFF BLOCKIEREN (SECURITY)
// =======================================================================
if ($action === 'set') {
    http_response_code(403);
    die(json_encode(["error" => "Public Write Access Forbidden (ReadOnly Mode)"]));
}


// =======================================================================
// 2. GET CACHE (Read Only)
// =======================================================================
if ($method === 'GET' && $action === 'get') {
    $key = $_GET['key'] ?? '';
    if (!$key) die(json_encode(null));

    // SECURE: Prepared Statement
    $stmt = $conn->prepare("SELECT cache_value FROM vFIDS_cache WHERE cache_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        echo $row['cache_value'];
    } else {
        echo json_encode(null);
    }
    $stmt->close();
    exit;
}


// =======================================================================
// 3. FETCH AIRPORTS
// =======================================================================
elseif ($method === 'GET' && $action === 'fetch_airports') {
    $key = 'vatsimFids_globalAirportsDB_v2'; // Neuer Key für neues Format

    // Cache Check
    $stmt = $conn->prepare("SELECT cache_value FROM vFIDS_cache WHERE cache_key = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($row = $res->fetch_assoc()) {
        if(!ob_start("ob_gzhandler")) ob_start();
        echo $row['cache_value'];
    } else {
        // Fetch Original Huge File
        $raw = @file_get_contents('https://raw.githubusercontent.com/mwgg/Airports/master/airports.json');
        
        if ($raw) {
            $data = json_decode($raw, true);
            $optimized = [];

            // MINIFICATION: Wir bauen ein kompaktes Array.
            // Format: "ICAO": [Lat, Lon, Elev, Iata, City, Name]
            foreach ($data as $icao => $ap) {
                // Wir filtern Müll raus (nur Airports mit ICAO Code)
                if (!$icao || strlen($icao) < 3) continue;

                // Daten extrahieren und Datentypen optimieren (Float/Int)
                // Index: 0=Lat, 1=Lon, 2=Elev, 3=IATA, 4=City, 5=Name
                $optimized[$icao] = [
                    round(floatval($ap['lat'] ?? 0), 4), // 4 Dezimalstellen reichen dicke
                    round(floatval($ap['lon'] ?? 0), 4),
                    intval($ap['elevation'] ?? 0),
                    $ap['iata'] ?? '',
                    $ap['city'] ?? '',
                    $ap['name'] ?? '',
					$ap['country'] ?? ''
                ];
            }

            $jsonOpt = json_encode($optimized);

            // Speichern
            $stmt = $conn->prepare("INSERT INTO vFIDS_cache (cache_key, cache_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()");
            $stmt->bind_param("ss", $key, $jsonOpt);
            $stmt->execute();
            $stmt->close();

            if(!ob_start("ob_gzhandler")) ob_start();
            echo $jsonOpt;
        } else {
            // Fallback falls GitHub down ist: Versuche alten Cache auch wenn expired
            $stmt = $conn->prepare("SELECT cache_value FROM vFIDS_cache WHERE cache_key = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) echo $row['cache_value'];
            else echo json_encode(["error" => "Fetch failed"]);
            $stmt->close();
        }
    }
    exit;
}


// =======================================================================
// 4. VATSIM FEED PROXY (Optimized)
// =======================================================================
elseif ($method === 'GET' && $action === 'feed') {
    // Session write close, damit parallele Requests (z.B. vom Worker) nicht blockiert werden
    session_write_close();

    $reqAirports = isset($_GET['airports']) ? array_filter(explode(',', strtoupper($_GET['airports']))) : [];
    $feedKey = 'vatsimFids_vatsimFeedCache_v1';

    // 1. Cache prüfen (TTL auf 15 Sekunden gesenkt -> Sync mit VATSIM Intervall)
    $stmt = $conn->prepare("SELECT cache_value, TIMESTAMPDIFF(SECOND, updated_at, NOW()) as age_sec FROM vFIDS_cache WHERE cache_key = ?");
    $stmt->bind_param("s", $feedKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    $jsonRaw = null;
    $cacheAge = $row ? (int)$row['age_sec'] : 999;

    // 2. Entscheidung: Cache nutzen oder neu laden?
    // Wir laden neu, wenn kein Cache da ist ODER wenn er älter als 15 Sekunden ist.
    if (!$row || $cacheAge >= 15) {
        
        // Timeout setzen (2s), damit das FIDS nicht hängt, wenn VATSIM laggt
        $ctx = stream_context_create(['http' => ['timeout' => 2.5]]);
        $freshData = @file_get_contents('https://data.vatsim.net/v3/vatsim-data.json', false, $ctx);

        if ($freshData) {
            // Validieren ob es wirklich JSON ist, bevor wir den Cache überschreiben
            $check = json_decode($freshData, true);
            if ($check && isset($check['general'])) {
                $jsonRaw = $freshData;
                
                // Cache in DB aktualisieren
                $stmt = $conn->prepare("INSERT INTO vFIDS_cache (cache_key, cache_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()");
                $stmt->bind_param("ss", $feedKey, $jsonRaw);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // 3. Fallback: Wenn Fetch fehlschlug (Timeout/Vatsim down), nimm den alten Cache, auch wenn er > 15s ist
    if (!$jsonRaw && $row) {
        $jsonRaw = $row['cache_value'];
    }

    if (!$jsonRaw) { 
        http_response_code(502); 
        die(json_encode(["error" => "VATSIM data unavailable"])); 
    }
    
    // 4. Filtering (Deine bestehende Logik)
    if (empty($reqAirports)) { 
        $d = json_decode($jsonRaw, true);
        // Gebe general info zurück, damit Frontend Sync-Zeit berechnen kann
        echo json_encode(["general" => $d['general'] ?? [], "pilots" => []]); 
        exit; 
    }

    $data = json_decode($jsonRaw, true);
    $filtered = ['general' => $data['general'] ?? [], 'pilots' => [], 'atis' => []];

    // Schnellerer Array-Lookup durch Flipping
    $targetAirports = array_flip($reqAirports);

    if (!empty($data['pilots'])) {
        foreach ($data['pilots'] as $p) {
            $dep = $p['flight_plan']['departure'] ?? '';
            $arr = $p['flight_plan']['arrival'] ?? '';
            // isset ist viel schneller als in_array
            if (isset($targetAirports[$dep]) || isset($targetAirports[$arr])) {
                $filtered['pilots'][] = $p;
            }
        }
    }
    if (!empty($data['atis'])) {
        foreach ($data['atis'] as $a) {
            $code = explode('_', $a['callsign'])[0];
            if (isset($targetAirports[$code])) {
                $filtered['atis'][] = $a;
            }
        }
    }
    
    // JSON Header explizit setzen und ausgeben
    header('Content-Type: application/json');
    echo json_encode($filtered);

    // --- GARBAGE COLLECTION (Moved here from SET action) ---
    // Cleanup mit 5% Wahrscheinlichkeit pro Request
    if (rand(1, 20) === 1) {
        // Alte Caches
        $conn->query("DELETE FROM vFIDS_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND (cache_key LIKE 'vatsimFids_gateCache%' OR cache_key LIKE 'vatsimFids_hotZoneCache%' OR cache_key LIKE 'vatsimFids_runwayCache%')");
        $conn->query("DELETE FROM vFIDS_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY) AND (cache_key LIKE 'vatsimFids_taxiwayCache%')");
        // Member Stats
        $conn->query("DELETE FROM vFIDS_members WHERE updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        // OSM Cache
        $conn->query("DELETE FROM vFIDS_osm WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        // Queue Zombies
        $conn->query("DELETE FROM vFIDS_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
    exit;
}


// =======================================================================
// 5. MEMBER STATS
// =======================================================================
elseif ($method === 'GET' && $action === 'member_stats') {
    $cid = intval($_GET['cid'] ?? 0);
    if (!$cid) die(json_encode(["error" => "No CID"]));

    // 1. Cache Check
    $stmt = $conn->prepare("SELECT stats_json, updated_at FROM vFIDS_members WHERE cid = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    // Cache valid (14 Tage)
    if ($row && (time() - strtotime($row['updated_at'])) < 1209600) {
        if ($row['stats_json'] === 'null') {
             http_response_code(404);
             echo json_encode(["error" => "Not found (Cached)"]);
             exit;
        }
        if ($row['stats_json']) {
            echo $row['stats_json'];
            exit;
        }
    }

    // 2. Queue Insert
    $status = 'pending';
    $stmt = $conn->prepare("INSERT IGNORE INTO vFIDS_stats_queue (cid, status) VALUES (?, ?)");
    $stmt->bind_param("is", $cid, $status);
    $stmt->execute();
    $stmt->close();

/*     // 3. Trigger Worker
    $workerPath = __DIR__ . '/vFIDS_stats_worker.php';
    if (file_exists($workerPath) && function_exists('exec')) {
        exec("/usr/bin/php $workerPath > /dev/null 2>&1 &");
    } */

    http_response_code(202); 
    echo json_encode(["status" => "queued", "msg" => "Fetching in background"]);
    exit;
}


// =======================================================================
// 6. OSM PROXY (SECURE QUERY BUILDER)
// =======================================================================
elseif (($method === 'POST' || $method === 'GET') && $action === 'osm_proxy') {
    
    // Parameter Validation
    $type = $_POST['req_type'] ?? '';
    $icao = strtoupper(trim($_POST['icao'] ?? ''));
    $lat  = (float)($_POST['lat'] ?? 0);
    $lon  = (float)($_POST['lon'] ?? 0);

    // Security: Whitelist & Format Check
    $allowedTypes = ['gates', 'taxiways', 'runways', 'holding'];
    if (!in_array($type, $allowedTypes)) {
        http_response_code(400); die(json_encode(["error" => "Invalid type"]));
    }
    if (!preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
        http_response_code(400); die(json_encode(["error" => "Invalid ICAO"]));
    }
    if ($lat == 0 || $lon == 0) {
        http_response_code(400); die(json_encode(["error" => "Invalid Coordinates"]));
    }

    // Server-Side Query Construction (No injection possible)
    $query = "";
    $head = "[out:json][timeout:25];";
    
    switch ($type) {
        case 'gates':
            // Radius 5000m um den Punkt
            $query = "$head (nwr[\"aeroway\"=\"gate\"](around:5000,$lat,$lon); nwr[\"aeroway\"=\"parking_position\"](around:5000,$lat,$lon);); out center tags;";
            break;
            
        case 'taxiways':
            // Radius 5000m, output geometry
            $query = "$head (nwr[\"aeroway\"=\"taxiway\"](around:5000,$lat,$lon); nwr[\"aeroway\"=\"taxiway_link\"](around:5000,$lat,$lon);); out geom;";
            break;
            
        case 'runways':
            // Radius 14000m (Runways sind lang)
            $query = "$head way[\"aeroway\"=\"runway\"](around:14000,$lat,$lon); out geom tags;";
            break;

        case 'holding':
            // Radius 6000m
            $query = "$head nwr[\"aeroway\"=\"holding_position\"](around:6000,$lat,$lon); out geom;";
            break;
    }

    // 1. Hash & Cache Check
    $hash = md5($query);
    $cacheKey = "vatsimFids_OSM_" . $hash;

    $stmt = $conn->prepare("SELECT cache_value FROM vFIDS_osm WHERE cache_key = ? LIMIT 1");
    $stmt->bind_param("s", $cacheKey);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        header('Content-Type: application/json');
        // GZIP compression falls möglich
        if(!ob_start("ob_gzhandler")) ob_start();
        echo $row['cache_value'];
        exit;
    }
    $stmt->close();

    // 2. Queue Check (Ist es schon in Arbeit?)
    $stmt = $conn->prepare("SELECT status FROM vFIDS_queue WHERE query_hash = ? LIMIT 1");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $qRes = $stmt->get_result();
    
    if ($qRow = $qRes->fetch_assoc()) {
        http_response_code(202); 
        echo json_encode(["status" => "processing"]);
        exit;
    }
    $stmt->close();

    // 3. Insert into Queue
    $status = 'pending';
    // Der Worker erwartet B64-Prefix wenn er Base64 decodieren soll.
    // Wir kodieren es hier, damit es kompatibel mit deinem bestehenden Worker-Code ist.
    $queryStored = 'B64:' . base64_encode($query);
    
    $stmt = $conn->prepare("INSERT INTO vFIDS_queue (query_hash, query_text, status, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("sss", $hash, $queryStored, $status);
    
    if ($stmt->execute()) {
 /*        // Trigger Worker
        $workerPath = __DIR__ . '/vFIDS_worker.php';
        $lockFile = __DIR__ . '/worker_running.lock';
        $isRunning = file_exists($lockFile) && (time() - filemtime($lockFile) < 120);

        if (!$isRunning && file_exists($workerPath) && function_exists('exec')) {
            exec("/usr/bin/php $workerPath > /dev/null 2>&1 &");
        }
 */
        http_response_code(202);
        echo json_encode(["status" => "queued"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "DB Error"]);
    }
    $stmt->close();
    exit;
}

$conn->close();
?>
