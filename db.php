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

// Roll the free monthly allowance if we've entered a new calendar month,
// then return [freeRemaining, paidCredits, totalAvailable] for a user row.
// Shared by auth.php (display) and api.php (spend check) so they never drift.
function creditStatus($user) {
    $pdo = db();
    if (!$pdo || !$user) return [0, 0, 0];
    $freeMonthly = (int)(getenv('FREE_MONTHLY') ?: 5);
    if (substr((string)$user['free_period_start'], 0, 7) !== gmdate('Y-m')) {
        $pdo->prepare('UPDATE users SET free_credits_used = 0, free_period_start = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d'), $user['id']]);
        $user['free_credits_used'] = 0;
    }
    $free = max(0, $freeMonthly - (int)$user['free_credits_used']);
    $paid = (int)$user['credits'];
    return [$free, $paid, $free + $paid];
}

// Send a transactional email via Resend's REST API (no SDK/composer — plain
// curl, same pattern as ebay-prices.php and checkout.php). Returns true on
// success, false on failure (logged, never thrown — callers should treat
// email as best-effort and not break the calling flow on failure).
function sendEmail($to, $subject, $html) {
    $key  = getenv('RESEND_API_KEY') ?: '';
    $from = getenv('RESEND_FROM') ?: '';
    if ($key === '' || $from === '') { error_log('[db.php] sendEmail: RESEND_API_KEY/RESEND_FROM not set'); return false; }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log('[db.php] sendEmail failed (HTTP ' . $code . '): ' . substr((string)$resp, 0, 300));
        return false;
    }
    return true;
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
