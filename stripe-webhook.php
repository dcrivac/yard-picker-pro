<?php
// Stripe webhook receiver. Listens for checkout.session.completed and
// credits the purchasing user's account. Verifies the signature manually
// (no Stripe SDK / composer dependency in this codebase).
//
// Configure in the Stripe Dashboard: Developers > Webhooks > Add endpoint
//   URL:    https://crivac.com/stripe-webhook.php
//   Events: checkout.session.completed
// Copy the "Signing secret" (whsec_...) into .env.php as STRIPE_WEBHOOK_SECRET.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
$payload = file_get_contents('php://input');
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if ($secret === '') { error_log('[stripe-webhook.php] STRIPE_WEBHOOK_SECRET not set'); http_response_code(503); exit; }

// ── Verify signature (manual, per Stripe's documented scheme) ─────────────
// Stripe-Signature: t=<timestamp>,v1=<hex hmac>[,v1=<hex hmac>...]
$parts = [];
foreach (explode(',', $sigHeader) as $kv) {
    $kv = explode('=', $kv, 2);
    if (count($kv) === 2) $parts[$kv[0]][] = $kv[1];
}
$timestamp = isset($parts['t'][0]) ? $parts['t'][0] : '';
$sigs = isset($parts['v1']) ? $parts['v1'] : [];
if ($timestamp === '' || empty($sigs)) { http_response_code(400); echo json_encode(['error' => 'Missing signature']); exit; }
if (abs(time() - (int)$timestamp) > 300) { http_response_code(400); echo json_encode(['error' => 'Signature too old']); exit; }

$expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
$verified = false;
foreach ($sigs as $sig) { if (hash_equals($expected, $sig)) { $verified = true; break; } }
if (!$verified) { error_log('[stripe-webhook.php] signature verification failed'); http_response_code(400); echo json_encode(['error' => 'Invalid signature']); exit; }

// ── Handle the event ────────────────────────────────────────────────────
$event = json_decode($payload, true);
if (!is_array($event)) { http_response_code(400); exit; }

if ($event['type'] === 'checkout.session.completed') {
    $session   = $event['data']['object'];
    $sessionId = isset($session['id']) ? $session['id'] : '';
    $userId    = isset($session['metadata']['user_id']) ? (int)$session['metadata']['user_id'] : 0;
    $credits   = isset($session['metadata']['credits']) ? (int)$session['metadata']['credits'] : 0;
    $paid      = isset($session['payment_status']) && $session['payment_status'] === 'paid';

    if ($paid && $userId > 0 && $credits > 0) {
        $pdo = db();
        if ($pdo) {
            // Idempotency: Stripe retries webhooks. The UNIQUE(ref, reason)
            // constraint on credit_events is the real guard — INSERT throws
            // on a duplicate session id, which we catch and treat as
            // "already credited" rather than an error.
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO credit_events (user_id, delta, reason, ref) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $credits, 'purchase', $sessionId]);
                $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$credits, $userId]);
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                if (strpos($e->getMessage(), 'uniq_ref_reason') === false) {
                    error_log('[stripe-webhook.php] credit insert failed: ' . $e->getMessage());
                }
            }
        }
    } else {
        error_log('[stripe-webhook.php] checkout.session.completed missing data: ' . substr($payload, 0, 300));
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
