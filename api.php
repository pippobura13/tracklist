<?php
/**
 * Hive Music - API Backend
 * Single-file router che gestisce tutte le richieste API
 * 
 * Endpoints:
 * - POST /api.php?action=register
 * - POST /api.php?action=login
 * - GET  /api.php?action=me
 * - GET  /api.php?action=check_username&username=xxx
 * - GET  /api.php?action=stats
 * - GET  /api.php?action=reviews&genre=xxx&sort=xxx&page=x
 * - GET  /api.php?action=review&id=xxx
 * - POST /api.php?action=review_create
 * - POST /api.php?action=review_like
 * - POST /api.php?action=draft_save
 * - GET  /api.php?action=feed&userId=xxx
 * - GET  /api.php?action=following
 * - GET  /api.php?action=suggestions
 * - POST /api.php?action=toggle_follow
 * - GET  /api.php?action=conversations
 * - GET  /api.php?action=messages&userId=xxx
 * - POST /api.php?action=message_send
 * - POST /api.php?action=messages_read
 * - GET  /api.php?action=notifications
 * - GET  /api.php?action=user&id=xxx
 */

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// JWT HELPER FUNCTIONS
// ============================================================

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function createJWT(array $payload): string {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;
    
    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);
    
    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    
    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
    
    $signature = base64UrlDecode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    
    if (!hash_equals($expectedSignature, $signature)) return null;
    
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;
    
    return $payload;
}

function getAuthUser(): ?array {
    // Apache/XAMPP a volte non inoltra l'header Authorization in $_SERVER.
    // Fallback multipli per leggerlo in modo affidabile.
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }
    if (!$authHeader && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }
    if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) return null;
    
    $payload = verifyJWT($matches[1]);
    if (!$payload || !isset($payload['user_id'])) return null;
    
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, username, email, display_name, bio, avatar_url, created_at FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non autenticato']);
        exit;
    }
    return $user;
}

// ============================================================
// SPOTIFY API HELPER FUNCTIONS
// ============================================================

function getSpotifyToken(): ?string {
    static $token = null;
    static $tokenExpiry = 0;
    
    if ($token && time() < $tokenExpiry) {
        return $token;
    }
    
    $ch = curl_init(SPOTIFY_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    
    $data = json_decode($response, true);
    if (!isset($data['access_token'])) return null;
    
    $token = $data['access_token'];
    $tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;
    
    return $token;
}

function spotifyRequest(string $endpoint): ?array {
    $token = getSpotifyToken();
    if (!$token) return null;
    
    $ch = curl_init(SPOTIFY_API_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    
    return json_decode($response, true);
}

function searchSpotifyAlbums(string $query): array {
    $endpoint = '/search?' . http_build_query([
        'q' => $query,
        'type' => 'album',
        'limit' => 10,
        'market' => 'IT'
    ]);
    
    $data = spotifyRequest($endpoint);
    if (!$data || !isset($data['albums']['items'])) return [];
    
    $albums = [];
    foreach ($data['albums']['items'] as $item) {
        $albums[] = [
            'spotify_id' => $item['id'],
            'title' => $item['name'],
            'artist' => $item['artists'][0]['name'] ?? 'Sconosciuto',
            'cover_url' => $item['images'][0]['url'] ?? null,
            'release_year' => substr($item['release_date'] ?? '', 0, 4),
            'tracks' => [] // Le tracce verranno caricate separatamente
        ];
    }
    
    return $albums;
}

function getSpotifyAlbumTracks(string $albumId): array {
    $endpoint = "/albums/$albumId/tracks?" . http_build_query(['limit' => 50, 'market' => 'IT']);
    $data = spotifyRequest($endpoint);
    
    if (!$data || !isset($data['items'])) return [];
    
    return array_map(fn($t) => $t['name'], $data['items']);
}

function getSpotifyAlbumDetails(string $albumId): ?array {
    $data = spotifyRequest("/albums/$albumId?market=IT");
    if (!$data) return null;
    
    // Estrai genere dal primo artista se disponibile
    $genre = null;
    if (!empty($data['genres'])) {
        $genre = $data['genres'][0];
    } else if (!empty($data['artists'][0]['id'])) {
        $artistData = spotifyRequest("/artists/" . $data['artists'][0]['id']);
        if (!empty($artistData['genres'])) {
            $genre = $artistData['genres'][0];
        }
    }
    
    return [
        'spotify_id' => $data['id'],
        'title' => $data['name'],
        'artist' => $data['artists'][0]['name'] ?? 'Sconosciuto',
        'cover_url' => $data['images'][0]['url'] ?? null,
        'release_year' => substr($data['release_date'] ?? '', 0, 4),
        'genre' => $genre,
        'tracks' => array_map(fn($t) => $t['name'], $data['tracks']['items'] ?? [])
    ];
}

// ============================================================
// RESPONSE HELPERS
// ============================================================

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $code = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function getInput(): array {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

// ============================================================
// API ACTIONS
// ============================================================

$action = $_GET['action'] ?? '';

switch ($action) {
    
    // --------------------------------------------------------
    // AUTH: Register
    // --------------------------------------------------------
    case 'register':
        $input = getInput();
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $displayName = trim("$firstName $lastName") ?: $username;
        
        if (strlen($username) < 3) errorResponse('Username deve avere almeno 3 caratteri');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) errorResponse('Email non valida');
        if (strlen($password) < 8) errorResponse('Password deve avere almeno 8 caratteri');
        
        $pdo = getDB();
        
        // Verifica duplicati
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) errorResponse('Username o email già in uso');
        
        // Inserisci utente
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $displayName]);
        $userId = $pdo->lastInsertId();
        
        $user = [
            'id' => (int)$userId,
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName
        ];
        
        $token = createJWT(['user_id' => $userId]);
        jsonResponse(['success' => true, 'token' => $token, 'user' => $user]);
        break;
    
    // --------------------------------------------------------
    // AUTH: Login
    // --------------------------------------------------------
    case 'login':
        $input = getInput();
        $identifier = trim($input['identifier'] ?? '');
        $password = $input['password'] ?? '';
        
        if (!$identifier || !$password) errorResponse('Credenziali mancanti');
        
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id, username, email, password_hash, display_name, bio, avatar_url FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            errorResponse('Credenziali non valide', 401);
        }
        
        unset($user['password_hash']);
        $user['id'] = (int)$user['id'];
        
        $token = createJWT(['user_id' => $user['id']]);
        jsonResponse(['success' => true, 'token' => $token, 'user' => $user]);
        break;
    
    // --------------------------------------------------------
    // AUTH: Me (Current User)
    // --------------------------------------------------------
    case 'me':
        $user = requireAuth();
        $user['id'] = (int)$user['id'];
        jsonResponse(['success' => true, 'user' => $user]);
        break;
    
    // --------------------------------------------------------
    // AUTH: Check Username Availability
    // --------------------------------------------------------
    case 'check_username':
        $username = trim($_GET['username'] ?? '');
        if (strlen($username) < 3) errorResponse('Username troppo corto');
        
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $exists = (bool)$stmt->fetch();
        
        jsonResponse(['success' => true, 'available' => !$exists]);
        break;
    
    // --------------------------------------------------------
    // STATS: Get Platform Stats
    // --------------------------------------------------------
    case 'stats':
        $pdo = getDB();
        
        $reviewsCount = $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
        $usersCount = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $albumsCount = $pdo->query('SELECT COUNT(DISTINCT album_id) FROM reviews')->fetchColumn();
        
        jsonResponse([
            'success' => true,
            'reviews_count' => (int)$reviewsCount,
            'users_count' => (int)$usersCount,
            'albums_count' => (int)$albumsCount
        ]);
        break;
    
    // --------------------------------------------------------
    // ALBUMS: Search via Spotify
    // --------------------------------------------------------
    case 'albums_search':
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) errorResponse('Query troppo corta');
        
        $albums = searchSpotifyAlbums($query);
        jsonResponse(['success' => true, 'albums' => $albums]);
        break;
    
    // --------------------------------------------------------
    // ALBUMS: Get tracks for a specific album
    // --------------------------------------------------------
    case 'album_tracks':
        $spotifyId = trim($_GET['spotify_id'] ?? '');
        if (!$spotifyId) errorResponse('Spotify ID mancante');

        // Check local cache first
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT tracks_json FROM albums WHERE spotify_id = ?');
        $stmt->execute([$spotifyId]);
        $row  = $stmt->fetch();

        if ($row && $row['tracks_json']) {
            $tracks = json_decode($row['tracks_json'], true) ?: [];
        } else {
            $tracks = getSpotifyAlbumTracks($spotifyId);
        }

        jsonResponse(['success' => true, 'tracks' => $tracks]);
        break;

    // --------------------------------------------------------
    // REVIEWS: List
    // --------------------------------------------------------
    case 'reviews':
        $genre = $_GET['genre'] ?? 'Tutti';
        $sort = $_GET['sort'] ?? 'new';
        $query = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = REVIEWS_PER_PAGE;
        $offset = ($page - 1) * $limit;

        $pdo = getDB();

        // Build query
        $where = [];
        $params = [];

        if ($genre && $genre !== 'Tutti') {
            $where[] = 'a.genre = ?';
            $params[] = $genre;
        }

        if ($query !== '') {
            $where[] = '(a.title LIKE ? OR a.artist LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Order by
        $orderBy = match($sort) {
            'pop' => 'likes_count DESC, r.created_at DESC',
            'top' => 'r.rating DESC, r.created_at DESC',
            default => 'r.created_at DESC'
        };
        
        $sql = "
            SELECT 
                r.id AS review_id,
                r.rating,
                r.body,
                r.fav_tracks_json,
                r.created_at,
                a.title AS album_title,
                a.artist,
                a.cover_url,
                a.genre,
                u.id AS user_id,
                u.username,
                u.display_name,
                u.avatar_url,
                (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = 1) AS likes_count,
                (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = -1) AS dislikes_count,
                (SELECT COUNT(*) FROM comments WHERE review_id = r.id) AS comments_count
            FROM reviews r
            JOIN albums a ON r.album_id = a.id
            JOIN users u ON r.user_id = u.id
            $whereClause
            ORDER BY $orderBy
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll();
        
        // Parse JSON fields
        foreach ($reviews as &$review) {
            $review['review_id'] = (int)$review['review_id'];
            $review['user_id'] = (int)$review['user_id'];
            $review['rating'] = (int)$review['rating'];
            $review['likes_count'] = (int)$review['likes_count'];
            $review['dislikes_count'] = (int)$review['dislikes_count'];
            $review['comments_count'] = (int)$review['comments_count'];
            $review['fav_tracks_json'] = json_decode($review['fav_tracks_json'] ?? '[]', true) ?: [];
        }
        
        jsonResponse(['success' => true, 'reviews' => $reviews, 'page' => $page]);
        break;
    
    // --------------------------------------------------------
    // REVIEWS: Get single review by ID
    // --------------------------------------------------------
    case 'review':
        $reviewId = (int)($_GET['id'] ?? 0);
        if (!$reviewId) errorResponse('Review ID mancante');

        $pdo = getDB();
        $sql = "
            SELECT
                r.id AS review_id,
                r.rating,
                r.body,
                r.fav_tracks_json,
                r.created_at,
                a.title AS album_title,
                a.artist,
                a.cover_url,
                a.genre,
                u.id AS user_id,
                u.username,
                u.display_name,
                u.avatar_url,
                (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = 1)  AS likes_count,
                (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = -1) AS dislikes_count,
                (SELECT COUNT(*) FROM comments WHERE review_id = r.id)             AS comments_count
            FROM reviews r
            JOIN albums a ON r.album_id = a.id
            JOIN users u  ON r.user_id  = u.id
            WHERE r.id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();

        if (!$review) errorResponse('Recensione non trovata', 404);

        $review['review_id']      = (int)$review['review_id'];
        $review['user_id']        = (int)$review['user_id'];
        $review['rating']         = (int)$review['rating'];
        $review['likes_count']    = (int)$review['likes_count'];
        $review['dislikes_count'] = (int)$review['dislikes_count'];
        $review['comments_count'] = (int)$review['comments_count'];
        $review['fav_tracks_json'] = json_decode($review['fav_tracks_json'] ?? '[]', true) ?: [];

        jsonResponse(['success' => true, 'review' => $review]);
        break;

    // --------------------------------------------------------
    // REVIEWS: Create
    // --------------------------------------------------------
    case 'review_create':
        $user = requireAuth();
        $input = getInput();
        
        $spotifyId = $input['spotify_id'] ?? '';
        $rating = (int)($input['rating'] ?? 0);
        $body = trim($input['body'] ?? '');
        $favTracks = $input['fav_tracks'] ?? [];
        
        if (!$spotifyId) errorResponse('Album non selezionato');
        if ($rating < 1 || $rating > 5) errorResponse('Valutazione non valida');
        if (strlen($body) < 10) errorResponse('Recensione troppo breve (minimo 10 caratteri)');
        
        $pdo = getDB();
        
        // Verifica se l'album esiste già nel DB, altrimenti lo crea
        $stmt = $pdo->prepare('SELECT id FROM albums WHERE spotify_id = ?');
        $stmt->execute([$spotifyId]);
        $album = $stmt->fetch();
        
        if (!$album) {
            // Recupera dettagli da Spotify e salva
            $details = getSpotifyAlbumDetails($spotifyId);
            if (!$details) errorResponse('Impossibile recuperare dati album da Spotify');
            
            $stmt = $pdo->prepare('
                INSERT INTO albums (spotify_id, title, artist, cover_url, release_year, genre, tracks_json)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $details['spotify_id'],
                $details['title'],
                $details['artist'],
                $details['cover_url'],
                $details['release_year'] ?: null,
                $details['genre'],
                json_encode($details['tracks'])
            ]);
            $albumId = $pdo->lastInsertId();
        } else {
            $albumId = $album['id'];
        }
        
        // Verifica se l'utente ha già recensito questo album
        $stmt = $pdo->prepare('SELECT id FROM reviews WHERE user_id = ? AND album_id = ?');
        $stmt->execute([$user['id'], $albumId]);
        if ($stmt->fetch()) {
            errorResponse('Hai già recensito questo album');
        }
        
        // Inserisci recensione
        $stmt = $pdo->prepare('
            INSERT INTO reviews (user_id, album_id, rating, body, fav_tracks_json)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['id'],
            $albumId,
            $rating,
            $body,
            json_encode($favTracks)
        ]);
        
        $reviewId = $pdo->lastInsertId();
        
        // Elimina eventuali bozze
        $stmt = $pdo->prepare('DELETE FROM drafts WHERE user_id = ? AND spotify_id = ?');
        $stmt->execute([$user['id'], $spotifyId]);
        
        jsonResponse(['success' => true, 'review_id' => (int)$reviewId]);
        break;

    // --------------------------------------------------------
    // REVIEWS: Delete (solo autore)
    // --------------------------------------------------------
    case 'review_delete':
        $user = requireAuth();
        $input = getInput();

        $reviewId = (int)($input['review_id'] ?? $_GET['review_id'] ?? 0);
        if (!$reviewId) errorResponse('Review ID mancante');

        $pdo = getDB();

        // Verifica proprietà
        $stmt = $pdo->prepare('SELECT user_id FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        $row = $stmt->fetch();

        if (!$row) errorResponse('Recensione non trovata', 404);
        if ((int)$row['user_id'] !== (int)$user['id']) errorResponse('Non sei autorizzato a eliminare questa recensione', 403);

        // Cancella (likes e comments sono ON DELETE CASCADE se schema corretto; lo faccio esplicitamente per sicurezza)
        $pdo->prepare('DELETE FROM likes    WHERE review_id = ?')->execute([$reviewId]);
        $pdo->prepare('DELETE FROM comments WHERE review_id = ?')->execute([$reviewId]);
        $pdo->prepare('DELETE FROM reviews  WHERE id = ?')->execute([$reviewId]);

        jsonResponse(['success' => true]);
        break;

    // --------------------------------------------------------
    // REVIEWS: Like/Dislike
    // --------------------------------------------------------
    case 'review_like':
        $user = requireAuth();
        $input = getInput();
        
        $reviewId = (int)($input['review_id'] ?? 0);
        $value = (int)($input['value'] ?? 0); // 1 = like, -1 = dislike, 0 = remove
        
        if (!$reviewId) errorResponse('Review ID mancante');
        if (!in_array($value, [-1, 0, 1])) errorResponse('Valore non valido');
        
        $pdo = getDB();
        
        // Verifica che la recensione esista
        $stmt = $pdo->prepare('SELECT id FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        if (!$stmt->fetch()) errorResponse('Recensione non trovata', 404);
        
        if ($value === 0) {
            // Rimuovi like/dislike
            $stmt = $pdo->prepare('DELETE FROM likes WHERE review_id = ? AND user_id = ?');
            $stmt->execute([$reviewId, $user['id']]);
        } else {
            // Upsert like/dislike
            $stmt = $pdo->prepare('SELECT id FROM likes WHERE review_id = ? AND user_id = ?');
            $stmt->execute([$reviewId, $user['id']]);
            
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('UPDATE likes SET value = ? WHERE review_id = ? AND user_id = ?');
                $stmt->execute([$value, $reviewId, $user['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO likes (review_id, user_id, value) VALUES (?, ?, ?)');
                $stmt->execute([$reviewId, $user['id'], $value]);
            }
        }
        
        // Conta likes/dislikes aggiornati
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM likes WHERE review_id = ? AND value = 1');
        $stmt->execute([$reviewId]);
        $likesCount = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM likes WHERE review_id = ? AND value = -1');
        $stmt->execute([$reviewId]);
        $dislikesCount = (int)$stmt->fetchColumn();
        
        jsonResponse([
            'success' => true,
            'likes_count' => $likesCount,
            'dislikes_count' => $dislikesCount
        ]);
        break;
    
    // --------------------------------------------------------
    // DRAFTS: Save
    // --------------------------------------------------------
    case 'draft_save':
        $user = requireAuth();
        $input = getInput();
        
        $spotifyId = $input['spotify_id'] ?? null;
        $rating = isset($input['rating']) ? (int)$input['rating'] : null;
        $body = isset($input['body']) ? trim($input['body']) : null;
        $favTracks = $input['fav_tracks'] ?? [];
        
        $pdo = getDB();
        
        // Verifica se esiste già una bozza
        $stmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $draft = $stmt->fetch();
        
        if ($draft) {
            $stmt = $pdo->prepare('
                UPDATE drafts SET spotify_id = ?, rating = ?, body = ?, fav_tracks_json = ?, updated_at = NOW()
                WHERE user_id = ?
            ');
            $stmt->execute([$spotifyId, $rating, $body, json_encode($favTracks), $user['id']]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO drafts (user_id, spotify_id, rating, body, fav_tracks_json)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$user['id'], $spotifyId, $rating, $body, json_encode($favTracks)]);
        }
        
        jsonResponse(['success' => true]);
        break;
    
    // --------------------------------------------------------
    // FEED: Get reviews from followed users
    // --------------------------------------------------------
    case 'feed':
        $user = requireAuth();
        $userId = isset($_GET['userId']) && $_GET['userId'] !== 'all' ? (int)$_GET['userId'] : null;
        
        $pdo = getDB();
        
        if ($userId) {
            // Recensioni di un utente specifico
            $sql = "
                SELECT 
                    r.id AS review_id,
                    r.rating,
                    r.body,
                    r.fav_tracks_json,
                    r.created_at,
                    a.title AS album_title,
                    a.artist,
                    a.cover_url,
                    a.genre,
                    u.id AS user_id,
                    u.username,
                    u.display_name,
                    (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = 1) AS likes_count,
                    (SELECT COUNT(*) FROM comments WHERE review_id = r.id) AS comments_count
                FROM reviews r
                JOIN albums a ON r.album_id = a.id
                JOIN users u ON r.user_id = u.id
                WHERE r.user_id = ?
                ORDER BY r.created_at DESC
                LIMIT 20
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
        } else {
            // Recensioni da tutti i seguiti
            $sql = "
                SELECT 
                    r.id AS review_id,
                    r.rating,
                    r.body,
                    r.fav_tracks_json,
                    r.created_at,
                    a.title AS album_title,
                    a.artist,
                    a.cover_url,
                    a.genre,
                    u.id AS user_id,
                    u.username,
                    u.display_name,
                    (SELECT COUNT(*) FROM likes WHERE review_id = r.id AND value = 1) AS likes_count,
                    (SELECT COUNT(*) FROM comments WHERE review_id = r.id) AS comments_count
                FROM reviews r
                JOIN albums a ON r.album_id = a.id
                JOIN users u ON r.user_id = u.id
                WHERE r.user_id IN (
                    SELECT following_id FROM followers WHERE follower_id = ?
                )
                ORDER BY r.created_at DESC
                LIMIT 30
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user['id']]);
        }
        
        $reviews = $stmt->fetchAll();
        
        foreach ($reviews as &$review) {
            $review['review_id'] = (int)$review['review_id'];
            $review['user_id'] = (int)$review['user_id'];
            $review['rating'] = (int)$review['rating'];
            $review['likes_count'] = (int)$review['likes_count'];
            $review['comments_count'] = (int)$review['comments_count'];
            $review['fav_tracks_json'] = json_decode($review['fav_tracks_json'] ?? '[]', true) ?: [];
        }
        
        jsonResponse(['success' => true, 'reviews' => $reviews]);
        break;
    
    // --------------------------------------------------------
    // USERS: Following list
    // --------------------------------------------------------
    case 'following':
        $user = requireAuth();
        $pdo = getDB();
        
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.display_name,
                u.avatar_url,
                (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS followers_count
            FROM users u
            JOIN followers f ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id']]);
        $users = $stmt->fetchAll();
        
        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['followers_count'] = (int)$u['followers_count'];
        }
        
        jsonResponse(['success' => true, 'users' => $users]);
        break;
    
    // --------------------------------------------------------
    // USERS: Suggestions
    // --------------------------------------------------------
    case 'suggestions':
        $user = requireAuth();
        $pdo = getDB();
        
        // Suggerisci utenti che non segui già, ordinati per numero di recensioni
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.display_name,
                u.avatar_url,
                (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS followers_count,
                (SELECT COUNT(*) FROM reviews WHERE user_id = u.id) AS reviews_count
            FROM users u
            WHERE u.id != ?
            AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
            ORDER BY reviews_count DESC, followers_count DESC
            LIMIT 10
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id'], $user['id']]);
        $users = $stmt->fetchAll();
        
        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['followers_count'] = (int)$u['followers_count'];
            unset($u['reviews_count']);
        }
        
        jsonResponse(['success' => true, 'users' => $users]);
        break;
    
    // --------------------------------------------------------
    // USERS: Toggle Follow
    // --------------------------------------------------------
    case 'toggle_follow':
        $user = requireAuth();
        $input = getInput();
        
        $targetId = (int)($input['user_id'] ?? 0);
        $follow = (bool)($input['follow'] ?? true);
        
        if (!$targetId || $targetId === (int)$user['id']) {
            errorResponse('ID utente non valido');
        }
        
        $pdo = getDB();
        
        // Verifica che l'utente target esista
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) errorResponse('Utente non trovato', 404);
        
        if ($follow) {
            // Segui (ignora se già segui)
            $stmt = $pdo->prepare('INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?, ?)');
            $stmt->execute([$user['id'], $targetId]);
        } else {
            // Smetti di seguire
            $stmt = $pdo->prepare('DELETE FROM followers WHERE follower_id = ? AND following_id = ?');
            $stmt->execute([$user['id'], $targetId]);
        }
        
        // Verifica stato attuale
        $stmt = $pdo->prepare('SELECT id FROM followers WHERE follower_id = ? AND following_id = ?');
        $stmt->execute([$user['id'], $targetId]);
        $isFollowing = (bool)$stmt->fetch();
        
        jsonResponse(['success' => true, 'following' => $isFollowing]);
        break;
    
    // --------------------------------------------------------
    // MESSAGES: Get Conversations
    // --------------------------------------------------------
    case 'conversations':
        $user = requireAuth();
        $pdo = getDB();
        
        // Query per ottenere tutte le conversazioni con l'ultimo messaggio
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.display_name,
                u.avatar_url,
                m.body AS last_message_body,
                m.created_at AS last_message_at,
                (
                    SELECT COUNT(*) FROM messages 
                    WHERE sender_id = u.id AND receiver_id = ? AND read_at IS NULL
                ) AS unread_count
            FROM users u
            JOIN (
                SELECT 
                    CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_user_id,
                    MAX(id) AS max_id
                FROM messages
                WHERE sender_id = ? OR receiver_id = ?
                GROUP BY other_user_id
            ) latest ON u.id = latest.other_user_id
            JOIN messages m ON m.id = latest.max_id
            ORDER BY m.created_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
        $rows = $stmt->fetchAll();
        
        $conversations = [];
        foreach ($rows as $row) {
            $conversations[] = [
                'user' => [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                    'avatar_url' => $row['avatar_url'],
                    'online' => false // Placeholder, richiederebbe un sistema di presence
                ],
                'last_message' => [
                    'body' => $row['last_message_body'],
                    'created_at' => $row['last_message_at']
                ],
                'unread_count' => (int)$row['unread_count']
            ];
        }
        
        jsonResponse(['success' => true, 'conversations' => $conversations]);
        break;
    
    // --------------------------------------------------------
    // MESSAGES: List messages with a user
    // --------------------------------------------------------
    case 'messages':
        $user = requireAuth();
        $otherId = (int)($_GET['userId'] ?? 0);

        if (!$otherId) errorResponse('User ID mancante');

        $pdo = getDB();
        $since = $_GET['since'] ?? null;

        if ($since) {
            $stmt = $pdo->prepare("
                SELECT id, sender_id, receiver_id, body, read_at, created_at
                FROM messages
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                  AND created_at > ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$user['id'], $otherId, $otherId, $user['id'], $since]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, sender_id, receiver_id, body, read_at, created_at
                FROM messages
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                ORDER BY created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$user['id'], $otherId, $otherId, $user['id'], MESSAGES_PER_PAGE]);
        }

        $messages = $stmt->fetchAll();

        foreach ($messages as &$msg) {
            $msg['id'] = (int)$msg['id'];
            $msg['sender_id'] = (int)$msg['sender_id'];
            $msg['receiver_id'] = (int)$msg['receiver_id'];
        }

        jsonResponse(['success' => true, 'messages' => $messages]);
        break;
    
    // --------------------------------------------------------
    // MESSAGES: Send
    // --------------------------------------------------------
    case 'message_send':
        $user = requireAuth();
        $input = getInput();
        
        $receiverId = (int)($input['receiver_id'] ?? 0);
        $body = trim($input['body'] ?? '');
        
        if (!$receiverId) errorResponse('Destinatario mancante');
        if (!$body) errorResponse('Messaggio vuoto');
        if ($receiverId === (int)$user['id']) errorResponse('Non puoi messaggiare te stesso');
        
        $pdo = getDB();
        
        // Verifica che il destinatario esista
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$receiverId]);
        if (!$stmt->fetch()) errorResponse('Destinatario non trovato', 404);
        
        // Inserisci messaggio
        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $receiverId, $body]);
        
        $messageId = $pdo->lastInsertId();
        
        // Recupera il messaggio inserito
        $stmt = $pdo->prepare('SELECT id, sender_id, receiver_id, body, read_at, created_at FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        $message['id'] = (int)$message['id'];
        $message['sender_id'] = (int)$message['sender_id'];
        $message['receiver_id'] = (int)$message['receiver_id'];
        
        jsonResponse(['success' => true, 'message' => $message]);
        break;
    
    // --------------------------------------------------------
    // MESSAGES: Mark as Read
    // --------------------------------------------------------
    case 'messages_read':
        $user = requireAuth();
        $input = getInput();
        
        $senderId = (int)($input['sender_id'] ?? 0);
        if (!$senderId) errorResponse('Sender ID mancante');
        
        $pdo = getDB();
        
        $stmt = $pdo->prepare('
            UPDATE messages SET read_at = NOW() 
            WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL
        ');
        $stmt->execute([$senderId, $user['id']]);
        
        jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
        break;
    
    // --------------------------------------------------------
    // NOTIFICATIONS: Get notifications for followed users' new reviews
    // --------------------------------------------------------
    case 'notifications':
        $user = requireAuth();
        $since = $_GET['since'] ?? null; // ISO timestamp
        
        $pdo = getDB();
        
        $params = [$user['id']];
        $whereTime = '';
        
        if ($since) {
            $whereTime = 'AND r.created_at > ?';
            $params[] = $since;
        } else {
            // Default: ultime 24 ore
            $whereTime = 'AND r.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        }
        
        $sql = "
            SELECT
                r.id AS review_id,
                r.created_at,
                a.title AS album_title,
                a.cover_url,
                u.id AS user_id,
                u.username,
                u.display_name,
                u.avatar_url
            FROM reviews r
            JOIN albums a ON r.album_id = a.id
            JOIN users u ON r.user_id = u.id
            WHERE r.user_id IN (
                SELECT following_id FROM followers WHERE follower_id = ?
            )
            $whereTime
            ORDER BY r.created_at DESC
            LIMIT 20
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        
        foreach ($notifications as &$n) {
            $n['review_id'] = (int)$n['review_id'];
            $n['user_id'] = (int)$n['user_id'];
        }
        
        jsonResponse(['success' => true, 'notifications' => $notifications]);
        break;
    
    // --------------------------------------------------------
    // USER: Get user profile
    // --------------------------------------------------------
    case 'user':
        $userId = (int)($_GET['id'] ?? 0);
        if (!$userId) errorResponse('User ID mancante');
        
        $pdo = getDB();
        
        $stmt = $pdo->prepare('
            SELECT id, username, display_name, bio, avatar_url, created_at
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        
        if (!$profile) errorResponse('Utente non trovato', 404);
        
        $profile['id'] = (int)$profile['id'];
        
        // Conta followers e following
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM followers WHERE following_id = ?');
        $stmt->execute([$userId]);
        $profile['followers_count'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM followers WHERE follower_id = ?');
        $stmt->execute([$userId]);
        $profile['following_count'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE user_id = ?');
        $stmt->execute([$userId]);
        $profile['reviews_count'] = (int)$stmt->fetchColumn();
        
        // Se loggato, verifica se l'utente corrente segue questo profilo
        $currentUser = getAuthUser();
        if ($currentUser) {
            $stmt = $pdo->prepare('SELECT id FROM followers WHERE follower_id = ? AND following_id = ?');
            $stmt->execute([$currentUser['id'], $userId]);
            $profile['is_following'] = (bool)$stmt->fetch();
        }
        
        jsonResponse(['success' => true, 'user' => $profile]);
        break;
    
    // --------------------------------------------------------
    // USERS: Search by username or display_name
    // --------------------------------------------------------
    case 'users_search':
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) errorResponse('Query troppo corta');

        $pdo  = getDB();
        $like = '%' . $query . '%';

        $currentUser = getAuthUser(); // optional, null if not logged in

        $stmt = $pdo->prepare("
            SELECT
                u.id, u.username, u.display_name, u.bio, u.avatar_url,
                (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS followers_count,
                (SELECT COUNT(*) FROM reviews   WHERE user_id      = u.id) AS reviews_count
            FROM users u
            WHERE u.username LIKE ? OR u.display_name LIKE ?
            ORDER BY reviews_count DESC
            LIMIT 12
        ");
        $stmt->execute([$like, $like]);
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['id']              = (int)$u['id'];
            $u['followers_count'] = (int)$u['followers_count'];
            $u['reviews_count']   = (int)$u['reviews_count'];
            if ($currentUser) {
                $chk = $pdo->prepare('SELECT id FROM followers WHERE follower_id = ? AND following_id = ?');
                $chk->execute([$currentUser['id'], $u['id']]);
                $u['is_following'] = (bool)$chk->fetch();
            }
        }

        jsonResponse(['success' => true, 'users' => $users]);
        break;

    // --------------------------------------------------------
    // COMMENTS: Get comments for a review
    // --------------------------------------------------------
    case 'comments':
        $reviewId = (int)($_GET['review_id'] ?? 0);
        if (!$reviewId) errorResponse('Review ID mancante');

        $pdo = getDB();

        $stmt = $pdo->prepare('SELECT id FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        if (!$stmt->fetch()) errorResponse('Recensione non trovata', 404);

        $stmt = $pdo->prepare("
            SELECT
                c.id, c.body, c.created_at,
                u.id AS user_id, u.username, u.display_name, u.avatar_url
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.review_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$reviewId]);
        $comments = $stmt->fetchAll();

        foreach ($comments as &$c) {
            $c['id']      = (int)$c['id'];
            $c['user_id'] = (int)$c['user_id'];
        }

        jsonResponse(['success' => true, 'comments' => $comments]);
        break;

    // --------------------------------------------------------
    // COMMENTS: Create comment
    // --------------------------------------------------------
    case 'comment_create':
        $user  = requireAuth();
        $input = getInput();

        $reviewId = (int)($input['review_id'] ?? 0);
        $body     = trim($input['body'] ?? '');

        if (!$reviewId) errorResponse('Review ID mancante');
        if (!$body)     errorResponse('Commento vuoto');
        if (strlen($body) > 1000) errorResponse('Commento troppo lungo (max 1000 caratteri)');

        $pdo = getDB();

        $stmt = $pdo->prepare('SELECT id FROM reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        if (!$stmt->fetch()) errorResponse('Recensione non trovata', 404);

        $stmt = $pdo->prepare('INSERT INTO comments (review_id, user_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$reviewId, $user['id'], $body]);
        $commentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT c.id, c.body, c.created_at,
                   u.id AS user_id, u.username, u.display_name, u.avatar_url
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        $comment['id']      = (int)$comment['id'];
        $comment['user_id'] = (int)$comment['user_id'];

        jsonResponse(['success' => true, 'comment' => $comment], 201);
        break;

    // --------------------------------------------------------
    // PROFILE: Update current user's profile
    // --------------------------------------------------------
    case 'profile_update':
        $user  = requireAuth();
        $input = getInput();

        $displayName     = isset($input['display_name'])  ? trim($input['display_name'])  : null;
        $bio             = isset($input['bio'])            ? trim($input['bio'])            : null;
        $avatarUrl       = isset($input['avatar_url'])     ? trim($input['avatar_url'])     : null;
        $newPassword     = $input['new_password']     ?? null;
        $currentPassword = $input['current_password'] ?? null;

        $pdo     = getDB();
        $updates = [];
        $params  = [];

        if ($displayName !== null) {
            if (strlen($displayName) < 1)   errorResponse('Nome visualizzato non può essere vuoto');
            if (strlen($displayName) > 100) errorResponse('Nome visualizzato troppo lungo (max 100 caratteri)');
            $updates[] = 'display_name = ?';
            $params[]  = $displayName;
        }

        if ($bio !== null) {
            $updates[] = 'bio = ?';
            $params[]  = $bio ?: null;
        }

        if ($avatarUrl !== null) {
            $updates[] = 'avatar_url = ?';
            $params[]  = $avatarUrl ?: null;
        }

        if ($newPassword) {
            if (strlen($newPassword) < 8) errorResponse('La nuova password deve avere almeno 8 caratteri');
            if (!$currentPassword)        errorResponse('Inserisci la password attuale');

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();

            if (!password_verify($currentPassword, $row['password_hash'])) {
                errorResponse('Password attuale non corretta', 403);
            }

            $updates[] = 'password_hash = ?';
            $params[]  = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if (empty($updates)) errorResponse('Nessun dato da aggiornare');

        $params[] = $user['id'];
        $sql      = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt     = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare('SELECT id, username, email, display_name, bio, avatar_url, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $updated       = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];

        jsonResponse(['success' => true, 'user' => $updated]);
        break;

    // --------------------------------------------------------
    // USERS: Followers of a user (public)
    // --------------------------------------------------------
    case 'user_followers':
        $userId = (int)($_GET['id'] ?? 0);
        if (!$userId) errorResponse('User ID mancante');

        $pdo = getDB();
        $currentUser = getAuthUser();

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.display_name, u.avatar_url,
                (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS followers_count
            FROM users u
            JOIN followers f ON u.id = f.follower_id
            WHERE f.following_id = ?
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['followers_count'] = (int)$u['followers_count'];
            if ($currentUser) {
                $chk = $pdo->prepare('SELECT id FROM followers WHERE follower_id = ? AND following_id = ?');
                $chk->execute([$currentUser['id'], $u['id']]);
                $u['is_following'] = (bool)$chk->fetch();
            }
        }

        jsonResponse(['success' => true, 'users' => $users]);
        break;

    // --------------------------------------------------------
    // USERS: Following list of a user (public)
    // --------------------------------------------------------
    case 'user_following':
        $userId = (int)($_GET['id'] ?? 0);
        if (!$userId) errorResponse('User ID mancante');

        $pdo = getDB();
        $currentUser = getAuthUser();

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.display_name, u.avatar_url,
                (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS followers_count
            FROM users u
            JOIN followers f ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['followers_count'] = (int)$u['followers_count'];
            if ($currentUser) {
                $chk = $pdo->prepare('SELECT id FROM followers WHERE follower_id = ? AND following_id = ?');
                $chk->execute([$currentUser['id'], $u['id']]);
                $u['is_following'] = (bool)$chk->fetch();
            }
        }

        jsonResponse(['success' => true, 'users' => $users]);
        break;

    // --------------------------------------------------------
    // AVATAR: Upload avatar image
    // --------------------------------------------------------
    case 'avatar_upload':
        $user = requireAuth();

        if (empty($_FILES['avatar'])) errorResponse('Nessun file caricato');

        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) errorResponse('Errore nel caricamento del file');
        if ($file['size'] > 5 * 1024 * 1024) errorResponse('Il file è troppo grande (max 5MB)');

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) errorResponse('Formato non supportato (usa JPG, PNG, GIF o WebP)');

        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $extMap[$mime];

        $dir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $pdo = getDB();

        // Remove old uploaded avatar if it was a local upload
        $stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $old = $stmt->fetch();
        if ($old && !empty($old['avatar_url']) && strpos($old['avatar_url'], 'uploads/avatars/') === 0) {
            $oldPath = __DIR__ . '/' . $old['avatar_url'];
            if (file_exists($oldPath)) @unlink($oldPath);
        }

        $filename = $user['id'] . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            errorResponse('Errore nel salvataggio del file');
        }

        $avatarUrl = 'uploads/avatars/' . $filename;
        $stmt = $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?');
        $stmt->execute([$avatarUrl, $user['id']]);

        $stmt = $pdo->prepare('SELECT id, username, email, display_name, bio, avatar_url, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];

        jsonResponse(['success' => true, 'user' => $updated, 'avatar_url' => $avatarUrl]);
        break;

    // --------------------------------------------------------
    // AVATAR: Delete own avatar (file + DB field)
    // --------------------------------------------------------
    case 'avatar_delete':
        $user = requireAuth();
        $pdo  = getDB();

        $stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $old = $stmt->fetch();

        // Rimuovi il file locale solo se è un upload locale (non URL esterno)
        if ($old && !empty($old['avatar_url']) && strpos($old['avatar_url'], 'uploads/avatars/') === 0) {
            $oldPath = __DIR__ . '/' . $old['avatar_url'];
            if (file_exists($oldPath)) @unlink($oldPath);
        }

        $stmt = $pdo->prepare('UPDATE users SET avatar_url = NULL WHERE id = ?');
        $stmt->execute([$user['id']]);

        $stmt = $pdo->prepare('SELECT id, username, email, display_name, bio, avatar_url, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $updated = $stmt->fetch();
        $updated['id'] = (int)$updated['id'];

        jsonResponse(['success' => true, 'user' => $updated]);
        break;

    // --------------------------------------------------------
    // Default: Unknown action
    // --------------------------------------------------------
    default:
        errorResponse('Azione non riconosciuta: ' . $action, 400);
}
