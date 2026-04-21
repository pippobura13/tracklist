<?php
/**
 * Hive Music - Database Connection
 * Connessione PDO al database MySQL/MariaDB
 */

require_once __DIR__ . '/config.php';

/**
 * Restituisce una connessione PDO singleton al database
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In produzione, loggare l'errore invece di mostrarlo
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Errore di connessione al database: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    return $pdo;
}

/**
 * Verifica se la connessione al database è attiva
 * @return bool
 */
function testConnection(): bool {
    try {
        $pdo = getDB();
        $pdo->query('SELECT 1');
        return true;
    } catch (Exception $e) {
        return false;
    }
}
