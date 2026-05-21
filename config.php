<?php
/**
 * Hive Music - Configuration File
 * Le credenziali sensibili sono caricate dal file .env (non versionato).
 */

// ============================================================
// ENV LOADER  — parser minimale per file .env
// ============================================================
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'File .env mancante. Copiare .env.example in .env e compilare i valori.']);
        exit;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    if (!getenv($key)) putenv("$key=$value");
}
})();

// ============================================================
// DATABASE CONFIGURATION
// ============================================================
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_NAME', $env['DB_NAME'] ?? 'hivemusic');
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// SPOTIFY API CREDENTIALS
// ============================================================
define('SPOTIFY_CLIENT_ID',     getenv('SPOTIFY_CLIENT_ID')     ?: 'd3859dddd2e44b88a30790a1c1f404dd');
define('SPOTIFY_CLIENT_SECRET', getenv('SPOTIFY_CLIENT_SECRET') ?: 'd3455ddc48414cdebed233dab5684a32');
define('SPOTIFY_TOKEN_URL',     'https://accounts.spotify.com/api/token');
define('SPOTIFY_API_URL',       'https://api.spotify.com/v1');

// ============================================================
// JWT CONFIGURATION
// ============================================================
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');
define('JWT_EXPIRY', 86400 * 7); // 7 giorni

// ============================================================
// APPLICATION SETTINGS
// ============================================================
define('APP_NAME',          'Hive Music');
define('APP_URL',           'http://localhost/hivemusic');
define('REVIEWS_PER_PAGE',  8);
define('MESSAGES_PER_PAGE', 50);

// ============================================================
// ERROR REPORTING (Development — disabilitare in produzione)
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('Europe/Rome');

// ============================================================
// CORS HEADERS (for development)
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
