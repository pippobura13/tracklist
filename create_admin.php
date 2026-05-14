<?php
/**
 * Hive Music - Create Admin Account
 * ============================================================
 * Script one-time: eseguire UNA SOLA VOLTA da browser o CLI,
 * poi ELIMINARE questo file dal server per sicurezza.
 *
 * Browser: http://localhost/hivemusic/create_admin.php
 * CLI:     php create_admin.php
 * ============================================================
 */

require_once __DIR__ . '/db_connect.php';

// ============================================================
// CREDENZIALI ADMIN — cambiare prima di eseguire
// ============================================================
$ADMIN_USERNAME     = 'admin';
$ADMIN_EMAIL        = 'admin@hivemusic.local';
$ADMIN_PASSWORD     = 'Admin@Hive2025!';   // ← CAMBIARE
$ADMIN_DISPLAY_NAME = 'Administrator';
// ============================================================

header('Content-Type: text/plain; charset=utf-8');

// Sicurezza: blocca se non siamo in localhost
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$remoteIP   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!in_array($remoteIP, $allowedIPs)) {
    http_response_code(403);
    echo "Accesso negato: questo script può essere eseguito solo da localhost.\n";
    exit;
}

$pdo = getDB();

// Verifica che la colonna `role` esista (migration già eseguita)
try {
    $pdo->query("SELECT role FROM users LIMIT 1");
} catch (PDOException $e) {
    echo "ERRORE: La colonna `role` non esiste.\n";
    echo "Eseguire prima migration_admin.sql sul database.\n";
    exit(1);
}

// Controlla se esiste già un admin
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'admin' LIMIT 5");
$stmt->execute();
$existing = $stmt->fetchAll();
if ($existing) {
    echo "Account admin già presenti:\n";
    foreach ($existing as $u) {
        echo "  - ID {$u['id']}: {$u['username']}\n";
    }
    echo "\nPer creare un altro admin, modificare \$ADMIN_USERNAME e \$ADMIN_EMAIL in questo file.\n";
}

// Verifica che username/email non siano già in uso
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
$stmt->execute([$ADMIN_USERNAME, $ADMIN_EMAIL]);
if ($stmt->fetch()) {
    echo "ERRORE: username '$ADMIN_USERNAME' o email '$ADMIN_EMAIL' già in uso.\n";
    echo "Modificare le credenziali nello script.\n";
    exit(1);
}

// Validazione password
if (strlen($ADMIN_PASSWORD) < 12) {
    echo "ERRORE: la password deve essere di almeno 12 caratteri.\n";
    exit(1);
}

// Hash sicuro della password
$hash = password_hash($ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);

// Inserimento
$stmt = $pdo->prepare("
    INSERT INTO users (username, email, password_hash, display_name, role, created_at, updated_at)
    VALUES (?, ?, ?, ?, 'admin', NOW(), NOW())
");
$stmt->execute([$ADMIN_USERNAME, $ADMIN_EMAIL, $hash, $ADMIN_DISPLAY_NAME]);
$adminId = $pdo->lastInsertId();

echo "============================================================\n";
echo " Account admin creato con successo!\n";
echo "============================================================\n";
echo "  ID:           $adminId\n";
echo "  Username:     $ADMIN_USERNAME\n";
echo "  Email:        $ADMIN_EMAIL\n";
echo "  Password:     $ADMIN_PASSWORD\n";
echo "  Display name: $ADMIN_DISPLAY_NAME\n";
echo "============================================================\n";
echo "\n*** AZIONI RICHIESTE ***\n";
echo "1. Annotare le credenziali in un password manager sicuro.\n";
echo "2. ELIMINARE questo file dal server:\n";
echo "   rm " . __FILE__ . "\n";
echo "3. Accedere al pannello admin: http://localhost/hivemusic/admin.html\n";
