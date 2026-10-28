<?php
// Profit Tracker endpoint. Same-origin (APP_ORIGIN, see config.php), session-cookie based.
// Requires a signed-in user (api.php already requires login to run an
// analysis, so by the time a "+ Track" button exists the user is signed in).
//
//   GET  tracker.php                                            -> list current user's tracker items
//   POST tracker.php {action:"add", part, car, lkqCost, laborCost, ebayAvg, reason}
//   POST tracker.php {action:"update_status", id, status, listPrice?, salePrice?}
//   POST tracker.php {action:"delete", id}
//
// All queries are prepared and every mutation is scoped to (id, user_id) so
// one account can never read or change another account's tracker rows.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config.php';
$allowedOrigins = allowedOrigins();
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out($data, $status = 200) { http_response_code($status); echo json_encode($data); exit; }

function itemOut($r) {
    return [
        'id'         => (int)$r['id'],
        'part'       => $r['part'],
        'car'        => $r['car'],
        'lkqCost'    => $r['lkq_cost']   !== null ? (float)$r['lkq_cost']   : null,
        'laborCost'  => $r['labor_cost'] !== null ? (float)$r['labor_cost'] : null,
        'ebayAvg'    => $r['ebay_avg']   !== null ? (float)$r['ebay_avg']   : null,
        'listPrice'  => $r['list_price'] !== null ? (float)$r['list_price'] : null,
        'salePrice'  => $r['sale_price'] !== null ? (float)$r['sale_price'] : null,
        'reason'     => $r['reason'],
        'status'     => $r['status'],
        'pulledAt'   => $r['pulled_at'] !== null ? strtotime($r['pulled_at']) * 1000 : null,
        'listedAt'   => $r['listed_at'] !== null ? strtotime($r['listed_at']) * 1000 : null,
        'soldAt'     => $r['sold_at']   !== null ? strtotime($r['sold_at'])   * 1000 : null,
    ];
}

$pdo = db();
if (!$pdo) out(['error' => 'Service unavailable'], 503);

$me = currentUser();
if (!$me) out(['error' => 'Sign in required'], 401);

// ── GET: list this user's tracker items ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM tracker_items WHERE user_id = ? ORDER BY created_at ASC');
    $stmt->execute([$me['id']]);
    out(['items' => array_map('itemOut', $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Method not allowed'], 405);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) out(['error' => 'Invalid request'], 400);
$action = isset($body['action']) ? $body['action'] : '';

// Converts a client epoch-ms timestamp (or nothing) to a MySQL datetime.
function tsOrNull($ms) {
    return !empty($ms) ? date('Y-m-d H:i:s', (int)((float)$ms / 1000)) : null;
}

// ── ADD ──────────────────────────────────────────────────────────────────
// Also accepts status/pulledAt/listedAt/salePrice/soldAt/listPrice so the
// one-time localStorage migration can preserve a part's original history
// instead of re-adding everything as freshly "pulled".
if ($action === 'add') {
    $part = isset($body['part']) ? trim((string)$body['part']) : '';
    if ($part === '') out(['error' => 'Missing part name'], 400);
    $status = (isset($body['status']) && in_array($body['status'], ['pulled', 'listed', 'sold'], true)) ? $body['status'] : 'pulled';
    $pulledAt = tsOrNull(isset($body['pulledAt']) ? $body['pulledAt'] : null) ?: date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO tracker_items
        (user_id, part, car, lkq_cost, labor_cost, ebay_avg, list_price, sale_price, reason, status, pulled_at, listed_at, sold_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $me['id'],
        $part,
        isset($body['car']) ? (string)$body['car'] : '',
        isset($body['lkqCost'])   ? (float)$body['lkqCost']   : null,
        isset($body['laborCost']) ? (float)$body['laborCost'] : null,
        isset($body['ebayAvg'])   ? (float)$body['ebayAvg']   : null,
        isset($body['listPrice']) ? (float)$body['listPrice'] : null,
        isset($body['salePrice']) ? (float)$body['salePrice'] : null,
        isset($body['reason']) ? (string)$body['reason'] : null,
        $status,
        $pulledAt,
        tsOrNull(isset($body['listedAt']) ? $body['listedAt'] : null),
        tsOrNull(isset($body['soldAt']) ? $body['soldAt'] : null),
    ]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM tracker_items WHERE id = ?');
    $stmt->execute([$id]);
    out(['item' => itemOut($stmt->fetch())]);
}

// ── UPDATE STATUS ────────────────────────────────────────────────────────
if ($action === 'update_status') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $status = isset($body['status']) ? (string)$body['status'] : '';
    if (!$id || !in_array($status, ['pulled', 'listed', 'sold'], true)) out(['error' => 'Invalid request'], 400);

    // Ownership check first, never trust the client's id alone.
    $stmt = $pdo->prepare('SELECT id FROM tracker_items WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $me['id']]);
    if (!$stmt->fetch()) out(['error' => 'Not found'], 404);

    $sets = ['status = ?'];
    $params = [$status];
    if ($status === 'listed' && isset($body['listPrice'])) {
        $sets[] = 'list_price = ?'; $params[] = (float)$body['listPrice'];
        $sets[] = 'listed_at = ?'; $params[] = date('Y-m-d H:i:s');
    }
    if ($status === 'sold' && isset($body['salePrice'])) {
        $sets[] = 'sale_price = ?'; $params[] = (float)$body['salePrice'];
        $sets[] = 'sold_at = ?'; $params[] = date('Y-m-d H:i:s');
    }
    $params[] = $id;
    $params[] = $me['id'];
    $pdo->prepare('UPDATE tracker_items SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);

    $stmt = $pdo->prepare('SELECT * FROM tracker_items WHERE id = ?');
    $stmt->execute([$id]);
    out(['item' => itemOut($stmt->fetch())]);
}

// ── DELETE ───────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) out(['error' => 'Invalid request'], 400);
    $pdo->prepare('DELETE FROM tracker_items WHERE id = ? AND user_id = ?')->execute([$id, $me['id']]);
    out(['ok' => true]);
}

out(['error' => 'Unknown action'], 400);
