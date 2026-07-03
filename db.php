<?php
// Shared database connection + auth session helpers.
// Credentials come from env / .env.php (same pattern as the API keys).
//
//   DB_HOST, DB_NAME, DB_USER, DB_PASS
//
// Provision the DB in SiteGround Site Tools > MySQL, then run schema.sql once.

$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) { require_once $envFile; }

// Return a shared PDO connection (or null if not configured / unreachable).
function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo ?: null;

    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    if ($name === '' || $user === '') { $pdo = false; return null; }

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Throwable $e) {
        error_log('[db.php] connection failed: ' . $e->getMessage());
        $pdo = false;
        return null;
    }
    return $pdo;
}

// Start a hardened session (idempotent).
function startSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 days
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('yp_sess');
    session_start();
}

// Return the current logged-in user row, or null. Cached per request.
function currentUser() {
    static $cached = null;
    static $loaded = false;
    if ($loaded) return $cached;
    $loaded = true;

    startSession();
    if (empty($_SESSION['uid'])) return $cached = null;
    $pdo = db();
    if (!$pdo) return $cached = null;

    $stmt = $pdo->prepare('SELECT id, email, credits, free_credits_used, free_period_start, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['uid']]);
    $row = $stmt->fetch();
    return $cached = ($row ?: null);
}
