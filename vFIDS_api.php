<?php
/**
 * VATSIM FIDS - WordPress Authentication Wrapper
 * Fixed Version: Supports POST & RAW JSON, prevents NULL spam
 */

// 1. WordPress laden & Authentifizierung
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
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(["error" => "Unauthorized - Please log in to WordPress"]));
}

// 2. Datenbank Konfiguration
$db_host = 'localhost';
$db_user = '';
$db_pass = '';
$db_name = 'vFIDS';

// 3. Header setzen
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header("Access-Control-Allow-Origin: " . site_url());
header("Access-Control-Allow-Credentials: true");

// 4. DB Verbindung
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Tabelle initialisieren
$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_cache (
    cache_key VARCHAR(100) PRIMARY KEY,
    cache_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- GET: Daten abrufen ---
if ($method === 'GET' && $action === 'get') {
    $key = $conn->real_escape_string($_GET['key'] ?? '');
    if (!$key) die(json_encode(null));

    $res = $conn->query("SELECT cache_value FROM vFIDS_cache WHERE cache_key = '$key'");
    if ($row = $res->fetch_assoc()) {
        echo $row['cache_value'];
    } else {
        echo json_encode(null);
    }
}

// --- POST: Daten speichern ---
elseif ($method === 'POST' && $action === 'set') {
    
    $key = '';
    $valueRaw = '';

    // Strategie A: Standard POST (vom neuen Frontend)
    if (!empty($_POST['key'])) {
        $key = $_POST['key'];
        // Das Frontend sendet hier bereits einen JSON-String via JSON.stringify()
        $valueRaw = $_POST['value'] ?? '';
    } 
    // Strategie B: Raw JSON Body (Fallback)
    else {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if ($data && isset($data['key'])) {
            $key = $data['key'];
            // Hier kommt ein PHP-Array an, das müssen wir erst zu einem JSON-String machen
            $valueRaw = json_encode($data['value'] ?? '');
        }
    }

    // --- SPAM SCHUTZ / VALIDIERUNG ---
    // Verhindert das Speichern von "null", leeren Strings oder leeren Objekten "{}"
    if (
        empty($key) || 
        empty($valueRaw) || 
        $valueRaw === 'null' || 
        $valueRaw === '""' || 
        $valueRaw === '{}' || 
        $valueRaw === '[]'
    ) {
        // Wir tun so als ob es geklappt hat, speichern aber nichts (spart DB Platz)
        // Oder werfen Error (zum Debuggen besser):
        echo json_encode(["status" => "ignored", "msg" => "Value empty or invalid"]);
        exit;
    }

    // Speichern
    $keyEsc = $conn->real_escape_string($key);
    $valEsc = $conn->real_escape_string($valueRaw);

    $sql = "INSERT INTO vFIDS_cache (cache_key, cache_value) 
            VALUES ('$keyEsc', '$valEsc') 
            ON DUPLICATE KEY UPDATE cache_value = '$valEsc'";

    if ($conn->query($sql)) {
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "SQL Error: " . $conn->error]);
    }

    // --- CLEANUP (wie gehabt, nur Syntax korrigiert) ---
    if (rand(1, 15) === 1) {
        // 14 Tage
        $conn->query("DELETE FROM vFIDS_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY) AND (cache_key LIKE 'vatsimFids_gateCache%' OR cache_key LIKE 'vatsimFids_hotZoneCache%' OR cache_key LIKE 'vatsimFids_runwayCache%')");
        
        // 7 Tage
        $conn->query("DELETE FROM vFIDS_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND (cache_key LIKE 'vatsimFids_memberCache%' OR cache_key LIKE 'vatsimFids_taxiwayCache%')");
        
        // 24 Stunden (Flüchtige Daten)
        $conn->query("DELETE FROM vFIDS_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (cache_key LIKE 'vatsimFids_actRunwayCache%' OR cache_key LIKE 'vatsimFids_etdCache%' OR cache_key LIKE 'vatsimFids_metarCache%' OR cache_key LIKE 'vatsimFids_gateFlightCache%')");
    }
}

$conn->close();
?>
