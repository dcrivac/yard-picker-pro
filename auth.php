<?php
// Auth endpoint. Same-origin (crivac.com), session-cookie based.
//
//   POST auth.php {action:"register", email, password}
//   POST auth.php {action:"login",    email, password}
//   POST auth.php {action:"forgot",   email}                  -> emails a reset link
//   POST auth.php {action:"reset",    token, password}        -> sets new password, logs in
//   POST auth.php {action:"logout"}
//   GET  auth.php            -> current session user (or {user:null})
//
// Passwords are bcrypt-hashed (password_hash). All queries are prepared.
// Reset tokens are single-use, expire in 1h, and only their SHA-256 hash is
// stored (see password_resets in schema.sql) — same principle as passwords.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$allowedOrigins = ['https://crivac.com', 'https://www.crivac.com'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out($data, $status = 200) { http_response_code($status); echo json_encode($data); exit; }
function publicUser($u) {
    if (!$u) return null;
    list($free, $paid, $total) = creditStatus($u);
    return ['email' => $u['email'], 'credits' => $total, 'free' => $free, 'paid' => $paid];
}

$pdo = db();
if (!$pdo) out(['error' => 'Service unavailable'], 503);

// ── GET: who am I ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out(['user' => publicUser(currentUser())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Method not allowed'], 405);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) out(['error' => 'Invalid request'], 400);
$action = isset($body['action']) ? $body['action'] : '';

// Per-IP rate limit on auth attempts (brute-force guard). 20 / 5 min.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rl = sys_get_temp_dir() . '/yp-auth-' . hash('sha256', $ip);
$now = time(); $hits = [];
if (is_file($rl)) foreach (explode("\n", trim(@file_get_contents($rl))) as $t) if ($t !== '' && (int)$t > $now - 300) $hits[] = (int)$t;
if (in_array($action, ['login', 'register', 'forgot', 'reset'], true)) {
    if (count($hits) >= 20) out(['error' => 'Too many attempts. Try again in a few minutes.'], 429);
    $hits[] = $now;
    @file_put_contents($rl, implode("\n", $hits), LOCK_EX);
}

// ── LOGOUT ────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    out(['user' => null]);
}

$pass = isset($body['password']) ? (string)$body['password'] : '';

// ── RESET (uses a token, not email — handled before the email check below) ─
if ($action === 'reset') {
    $token = isset($body['token']) ? (string)$body['token'] : '';
    if ($token === '' || strlen($pass) < 8) out(['error' => 'Enter a new password (8+ characters).'], 400);

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) out(['error' => 'This reset link is invalid or has expired. Request a new one.'], 400);

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);

    startSession();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['user_id'];
    out(['user' => publicUser(currentUser())]);
}

// ── validate email for register/login/forgot ──────────────────────────────
$email = isset($body['email']) ? strtolower(trim($body['email'])) : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) out(['error' => 'Enter a valid email.'], 400);

// ── FORGOT PASSWORD ─────────────────────────────────────────────────────
// Always returns the same generic response whether or not the email is
// registered — prevents using this endpoint to enumerate accounts.
if ($action === 'forgot') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
            ->execute([$row['id'], hash('sha256', $token)]);
        $link = 'https://crivac.com/yp2.html?reset=' . urlencode($token);
        sendEmail($email, 'Reset your Yard Picker Pro password', '
            <p>Someone (hopefully you) requested a password reset for your Yard Picker Pro account.</p>
            <p><a href="' . htmlspecialchars($link) . '">Click here to set a new password</a> (link expires in 1 hour).</p>
            <p>If you did not request this, you can ignore this email.</p>
        ');
    }
    out(['ok' => true]);
}

// ── REGISTER ──────────────────────────────────────────────────────────────
if ($action === 'register') {
    if (strlen($pass) < 8) out(['error' => 'Password must be at least 8 characters.'], 400);
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) out(['error' => 'That email is already registered. Try logging in.'], 409);

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, free_period_start) VALUES (?, ?, CURDATE())');
    $ins->execute([$email, $hash]);
    $uid = (int)$pdo->lastInsertId();

    startSession();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    out(['user' => publicUser(currentUser())]);
}

// ── LOGIN ─────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($pass, $row['password_hash'])) {
        out(['error' => 'Wrong email or password.'], 401);
    }
    startSession();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    out(['user' => publicUser(currentUser())]);
}

out(['error' => 'Unknown action'], 400);
