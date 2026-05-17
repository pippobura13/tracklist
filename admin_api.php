<?php
/**
 * Hive Music - Admin API
 * ============================================================
 * Endpoint riservati agli utenti con role = 'admin'.
 * Tutti richiedono un JWT valido + role admin nel token.
 *
 * Endpoints:
 * - GET  admin_api.php?action=admin_users          — lista utenti
 * - POST admin_api.php?action=admin_ban_user       — banna utente
 * - POST admin_api.php?action=admin_unban_user     — rimuove ban
 * - POST admin_api.php?action=admin_delete_review  — elimina recensione
 * - POST admin_api.php?action=admin_delete_comment — elimina commento
 * - GET  admin_api.php?action=admin_log            — audit log
 * ============================================================
 */

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// JWT HELPERS (duplicati da api.php per autonomia del file)
// ============================================================

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
    $signature         = base64UrlDecode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    if (!hash_equals($expectedSignature, $signature)) return null;
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;
    return $payload;
}

function getBearerToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v)
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v)
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', $h, $m)) return $m[1];
    return null;
}

// ============================================================
// AUTH + ADMIN CHECK
// ============================================================

/**
 * Restituisce l'utente autenticato con role admin.
 * Esce con 401/403 in caso contrario.
 */
function requireAdmin(): array {
    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token mancante']);
        exit;
    }
    $payload = verifyJWT($token);
    if (!$payload || !isset($payload['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token non valido o scaduto']);
        exit;
    }
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, username, email, display_name, role FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Utente non trovato']);
        exit;
    }
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Accesso riservato agli amministratori']);
        exit;
    }
    return $user;
}

// ============================================================
// UTILITY
// ============================================================

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function errorResponse(string $msg, int $code = 400): void {
    jsonResponse(['success' => false, 'error' => $msg], $code);
}
function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

/** Scrive una voce nell'audit log */
function auditLog(int $adminId, string $action, string $targetType, int $targetId, string $details = ''): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare('
        INSERT INTO admin_log (admin_id, action, target_type, target_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$adminId, $action, $targetType, $targetId, $details]);
}

// ============================================================
// ROUTER
// ============================================================

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

switch ($action) {

    // ----------------------------------------------------------
    // GET admin_users — lista di tutti gli utenti con stato ban
    // Parametri: ?page=1&search=xxx&filter=all|banned|admin
    // ----------------------------------------------------------
    case 'admin_users': {
        $admin = requireAdmin();
        $pdo   = getDB();

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = 20;
        $offset  = ($page - 1) * $limit;
        $search  = trim($_GET['search'] ?? '');
        $filter  = $_GET['filter'] ?? 'all'; // all | banned | admin

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $like     = "%$search%";
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ($filter === 'banned') {
            $where[]  = 'banned_until IS NOT NULL AND banned_until > NOW()';
        } elseif ($filter === 'admin') {
            $where[]  = "role = 'admin'";
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSQL");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, username, email, display_name, role,
                   banned_until, ban_reason, avatar_url, created_at,
                   CASE
                     WHEN banned_until IS NULL THEN 'active'
                     WHEN banned_until >= '9999-12-31' THEN 'banned_permanent'
                     WHEN banned_until > NOW() THEN 'banned_temp'
                     ELSE 'active'
                   END AS ban_status
            FROM users $whereSQL
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $limit)
        ]);
    }

    // ----------------------------------------------------------
    // POST admin_ban_user — banna un utente
    // Body: { userId, duration: "1d"|"7d"|"30d"|"permanent", reason }
    // ----------------------------------------------------------
    case 'admin_ban_user': {
        $admin = requireAdmin();
        $body  = getInput();

        $userId   = (int)($body['userId'] ?? 0);
        $duration = $body['duration'] ?? '';
        $reason   = trim($body['reason'] ?? '');

        if (!$userId)    errorResponse('userId mancante');
        if (!$duration)  errorResponse('duration mancante (1d, 7d, 30d, permanent)');
        if ($userId === $admin['id']) errorResponse('Non puoi bannare te stesso');

        // Calcola la data di scadenza del ban
        $banUntil = match($duration) {
            '1d'        => date('Y-m-d H:i:s', strtotime('+1 day')),
            '7d'        => date('Y-m-d H:i:s', strtotime('+7 days')),
            '30d'       => date('Y-m-d H:i:s', strtotime('+30 days')),
            '90d'       => date('Y-m-d H:i:s', strtotime('+90 days')),
            'permanent' => '9999-12-31 23:59:59',
            default     => null
        };
        if (!$banUntil) errorResponse('Durata non valida. Usare: 1d, 7d, 30d, 90d, permanent');

        $pdo  = getDB();

        // Verifica che l'utente esista e non sia admin
        $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target)              errorResponse('Utente non trovato', 404);
        if ($target['role'] === 'admin') errorResponse('Non puoi bannare un altro amministratore');

        $stmt = $pdo->prepare('UPDATE users SET banned_until = ?, ban_reason = ? WHERE id = ?');
        $stmt->execute([$banUntil, $reason ?: null, $userId]);

        $label = $duration === 'permanent' ? 'permanente' : "fino al $banUntil";
        auditLog($admin['id'], 'ban_user', 'user', $userId,
            "Ban $label. Motivo: $reason. Target: {$target['username']}");

        jsonResponse([
            'success'    => true,
            'message'    => "Utente {$target['username']} bannato ($label)",
            'banned_until' => $banUntil
        ]);
    }

    // ----------------------------------------------------------
    // POST admin_unban_user — rimuove il ban
    // Body: { userId }
    // ----------------------------------------------------------
    case 'admin_unban_user': {
        $admin = requireAdmin();
        $body  = getInput();
        $userId = (int)($body['userId'] ?? 0);
        if (!$userId) errorResponse('userId mancante');

        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) errorResponse('Utente non trovato', 404);

        $stmt = $pdo->prepare('UPDATE users SET banned_until = NULL, ban_reason = NULL WHERE id = ?');
        $stmt->execute([$userId]);

        auditLog($admin['id'], 'unban_user', 'user', $userId,
            "Ban rimosso per {$target['username']}");

        jsonResponse(['success' => true, 'message' => "Ban rimosso per {$target['username']}"]);
    }

    // ----------------------------------------------------------
    // POST admin_delete_review — elimina una recensione
    // Body: { reviewId, reason }
    // ----------------------------------------------------------
    case 'admin_delete_review': {
        $admin = requireAdmin();
        $body  = getInput();
        $reviewId = (int)($body['reviewId'] ?? 0);
        $reason   = trim($body['reason'] ?? '');
        if (!$reviewId) errorResponse('reviewId mancante');

        $pdo  = getDB();
        $stmt = $pdo->prepare('
            SELECT r.id, r.body, u.username
            FROM reviews r JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) errorResponse('Recensione non trovata', 404);

        $stmt = $pdo->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);

        auditLog($admin['id'], 'delete_review', 'review', $reviewId,
            "Recensione di {$review['username']} eliminata. Motivo: $reason");

        jsonResponse(['success' => true, 'message' => "Recensione #$reviewId eliminata"]);
    }

    // ----------------------------------------------------------
    // POST admin_delete_comment — elimina un commento
    // Body: { commentId, reason }
    // ----------------------------------------------------------
    case 'admin_delete_comment': {
        $admin = requireAdmin();
        $body  = getInput();
        $commentId = (int)($body['commentId'] ?? 0);
        $reason    = trim($body['reason'] ?? '');
        if (!$commentId) errorResponse('commentId mancante');

        $pdo  = getDB();
        $stmt = $pdo->prepare('
            SELECT c.id, c.body, u.username
            FROM comments c JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ');
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        if (!$comment) errorResponse('Commento non trovato', 404);

        $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
        $stmt->execute([$commentId]);

        auditLog($admin['id'], 'delete_comment', 'comment', $commentId,
            "Commento di {$comment['username']} eliminato. Motivo: $reason");

        jsonResponse(['success' => true, 'message' => "Commento #$commentId eliminato"]);
    }

    // ----------------------------------------------------------
    // GET admin_log — audit log delle azioni admin
    // ----------------------------------------------------------
    case 'admin_log': {
        $admin  = requireAdmin();
        $pdo    = getDB();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 30;
        $offset = ($page - 1) * $limit;

        $total = (int)$pdo->query('SELECT COUNT(*) FROM admin_log')->fetchColumn();

        $stmt = $pdo->prepare('
            SELECT al.*, u.username AS admin_username
            FROM admin_log al
            JOIN users u ON al.admin_id = u.id
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$limit, $offset]);
        $logs = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'logs'    => $logs,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $limit)
        ]);
    }

    // ----------------------------------------------------------
    // GET admin_stats — statistiche generali per la dashboard
    // ----------------------------------------------------------
    case 'admin_stats': {
        requireAdmin();
        $pdo = getDB();

        $stats = [
            'total_users'    => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'total_reviews'  => (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn(),
            'total_comments' => (int)$pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn(),
            'total_messages' => (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn(),
            'banned_users'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE banned_until IS NOT NULL AND banned_until > NOW()")->fetchColumn(),
            'new_users_7d'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
            'new_reviews_7d' => (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
        ];

        jsonResponse(['success' => true, 'stats' => $stats]);
    }

    // ----------------------------------------------------------
    // POST admin_promote — promuove/degrada un utente ad admin
    // Body: { userId, role: 'admin'|'user' }
    // ----------------------------------------------------------
    case 'admin_set_role': {
        $admin = requireAdmin();
        $body  = getInput();
        $userId = (int)($body['userId'] ?? 0);
        $role   = $body['role'] ?? '';
        if (!$userId)                         errorResponse('userId mancante');
        if (!in_array($role, ['admin','user'])) errorResponse('role deve essere admin o user');
        if ($userId === $admin['id'])          errorResponse('Non puoi modificare il tuo stesso ruolo');

        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) errorResponse('Utente non trovato', 404);

        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $userId]);

        auditLog($admin['id'], 'set_role', 'user', $userId,
            "Ruolo di {$target['username']} impostato a: $role");

        jsonResponse(['success' => true, 'message' => "Ruolo di {$target['username']} → $role"]);
    }

    // ----------------------------------------------------------
    // GET admin_comments — lista di tutti i commenti con paginazione
    // Parametri: ?page=1
    // ----------------------------------------------------------
    case 'admin_comments': {
        $admin  = requireAdmin();
        $pdo    = getDB();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $total = (int)$pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn();

        $stmt = $pdo->prepare('
            SELECT c.id, c.body, c.created_at, c.review_id,
                   u.username
            FROM comments c
            JOIN users u ON c.user_id = u.id
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$limit, $offset]);
        $comments = $stmt->fetchAll();

        foreach ($comments as &$c) {
            $c['id']        = (int)$c['id'];
            $c['review_id'] = (int)$c['review_id'];
        }

        jsonResponse([
            'success'  => true,
            'comments' => $comments,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int)ceil($total / $limit)
        ]);
    }

    default:
        errorResponse('Azione admin non riconosciuta: ' . $action, 400);
}
