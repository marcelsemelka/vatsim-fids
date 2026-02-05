<?php
/**
 * vFIDS MySQL Backend API
 * Secure Version: Requires WordPress Authentication
 * Update: Complex Cleanup Rules
 */

// 1. WordPress laden & Authentifizierung prüfen (Sicherheit!)
$possible_paths = [
    '/var/www/html/wordpress/wp-load.php',  // <--- KORREKTER PFAD HINZUGEFÜGT
    __DIR__ . '/../wordpress/wp-load.php',  // <--- Alternative relative Schreibweise
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

// Sicherheitscheck: Abbruch, wenn User nicht eingeloggt ist
if (!$wp_loaded || !is_user_logged_in()) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(["error" => "Unauthorized - Please log in to WordPress"]));
}

// 2. Datenbank Konfiguration
$db_host = '';
$db_user = '';
$db_pass = '';
$db_name = 'vFIDS';

// 3. Header setzen (Verhindert oft NetworkErrors)
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
// Falls du über verschiedene Subdomains zugreifst, CORS erlauben:
header("Access-Control-Allow-Origin: " . site_url());
header("Access-Control-Allow-Credentials: true");

// 4. DB Verbindung aufbauen
// Wir nutzen hier @ um PHP-Fehler im Output zu unterdrücken, damit das JSON valide bleibt
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Initialisierung der Tabelle (falls noch nicht existent)
$conn->query("CREATE TABLE IF NOT EXISTS vFIDS_cache (
    cache_key VARCHAR(100) PRIMARY KEY,
    cache_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: Daten abrufen
if ($method === 'GET' && $action === 'get') {
    $key = $conn->real_escape_string($_GET['key'] ?? '');
    if (!$key) die(json_encode(null));

    $res = $conn->query("SELECT cache_value FROM vFIDS_cache WHERE cache_key = '$key'");
    if ($row = $res->fetch_assoc()) {
        // Die Daten liegen bereits als JSON-String in der DB (durch das JS frontend)
        // Wir geben sie direkt aus, ohne erneutes json_encode, um Double-Encoding zu vermeiden
        echo $row['cache_value'];
    } else {
        echo json_encode(null);
    }
}

// POST: Daten speichern
elseif ($method === 'POST' && $action === 'set') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Validierung
    if (!$data) {
        http_response_code(400);
        die(json_encode(["error" => "Invalid JSON body"]));
    }

    $key = $conn->real_escape_string($data['key'] ?? '');
    // WICHTIG: Das Value ist ein Objekt/Array aus JS, wir müssen es als String speichern
    $valueRaw = json_encode($data['value'] ?? '');
    $value = $conn->real_escape_string($valueRaw);

    if ($key) {
        $sql = "INSERT INTO vFIDS_cache (cache_key, cache_value)
                VALUES ('$key', '$value')
                ON DUPLICATE KEY UPDATE cache_value = '$value'";

        if ($conn->query($sql)) {
            echo json_encode(["status" => "success"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "SQL Error: " . $conn->error]);
        }

        // --- Bereinigungs-Logik (Cleanup) ---
        // Läuft statistisch bei jedem 15. Schreibzugriff (ca. 6%)
        if (rand(1, 15) === 1) {

            // 1. Retention: 14 Tage (Geometrie & statische Caches)
            $conn->query("DELETE FROM vFIDS_cache
                          WHERE updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
                          AND (
                              cache_key LIKE 'vatsimFids_gateCache%' OR
                              cache_key LIKE 'vatsimFids_hotZoneCache%' OR
                              cache_key LIKE 'vatsimFids_runwayCache%'
                          )");

            // 2. Retention: 7 Tage (Mitglieder & Wetter)
            $conn->query("DELETE FROM vFIDS_cache
                          WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                          AND (
                              cache_key LIKE 'vatsimFids_memberCache%' OR
                              cache_key LIKE 'vatsimFids_metarCache%'
                          )");

            // 3. Retention: 24 Stunden (Hochfrequente/Flüchtige Daten)
            $conn->query("DELETE FROM vFIDS_cache
                          WHERE updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          AND (
                              cache_key LIKE 'vatsimFids_actRunwayCache%' OR
                              cache_key LIKE 'vatsimFids_etdCache%' OR
                              cache_key LIKE 'vatsimFids_gateFlightCache%' OR
                              cache_key LIKE 'vatsimFids_taxiwayCache%'

                          )");

            // 4. Besonderheit: Taxi Times (JSON Inhalt filtern)
            // Hier müssen wir das JSON laden, Einträge prüfen und zurückschreiben
            $taxiRes = $conn->query("SELECT cache_key, cache_value FROM vFIDS_cache WHERE cache_key LIKE 'vatsimFids_taxiTimes%'");

            if ($taxiRes) {
                while ($row = $taxiRes->fetch_assoc()) {
                    $json = json_decode($row['cache_value'], true);
                    if (!$json || !is_array($json)) continue;

                    $hasChanges = false;
                    $nowMs = time() * 1000; // JS nutzt Millisekunden
                    $day14Ms = 14 * 24 * 3600 * 1000;
                    $hour24Ms = 24 * 3600 * 1000;

                    // Iteriere über Airports (Keys im JSON, z.B. "EDDF")
                    foreach ($json as $icao => $airportData) {
                        if (!isset($airportData['entries']) || !is_array($airportData['entries'])) continue;

                        $originalCount = count($airportData['entries']);
                        $newEntries = [];

                        foreach ($airportData['entries'] as $entry) {
                            $ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
                            $rwy = $entry['rwy'] ?? 'UNK';
                            $age = $nowMs - $ts;

                            // Regel A: Alles älter als 14 Tage löschen
                            if ($age > $day14Ms) {
                                continue;
                            }

                            // Regel B: Wenn RWY == "UNK", dann schon nach 24h löschen
                            if ($rwy === 'UNK' && $age > $hour24Ms) {
                                continue;
                            }

                            $newEntries[] = $entry;
                        }

                        // Wenn Einträge gelöscht wurden, übernehme die Änderung
                        if (count($newEntries) !== $originalCount) {
                            $json[$icao]['entries'] = $newEntries;

                            // Optional: Wenn keine Entries mehr da sind, Airport Key entfernen?
                            if (empty($newEntries)) unset($json[$icao]);

                            $hasChanges = true;
                        }
                    }

                    // Nur Update fahren, wenn wirklich was gelöscht wurde
                    if ($hasChanges) {
                        $newVal = $conn->real_escape_string(json_encode($json));
                        $cKey = $row['cache_key'];
                        $conn->query("UPDATE vFIDS_cache SET cache_value = '$newVal' WHERE cache_key = '$cKey'");
                    }
                }
            }
        }
    } else {
        echo json_encode(["error" => "Missing key"]);
    }
}

$conn->close();
?>
