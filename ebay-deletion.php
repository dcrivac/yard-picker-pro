<?php
// eBay Marketplace Account Deletion / Closure Notification endpoint.
//
// Two responsibilities, per eBay's spec:
//   1. Verification handshake (GET ?challenge_code=...):
//      Return {"challengeResponse": sha256(challengeCode + token + endpoint)}.
//   2. Deletion notifications (POST JSON):
//      Acknowledge with 200. Yard Picker Pro stores no eBay user data, so
//      there's nothing to scrub — we just log the event for audit purposes.

$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) { require $envFile; }

$VERIFICATION_TOKEN = getenv('EBAY_VERIFICATION_TOKEN') ?: '';
// Must exactly match the URL configured in the eBay developer portal.
$ENDPOINT_URL = 'https://crivac.com/ebay-deletion.php';

header('Content-Type: application/json');

if ($VERIFICATION_TOKEN === '') {
    error_log('[ebay-deletion.php] EBAY_VERIFICATION_TOKEN env var not set');
    http_response_code(500);
    echo json_encode(['error' => 'Misconfigured']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challengeCode = isset($_GET['challenge_code']) ? $_GET['challenge_code'] : '';
    if ($challengeCode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing challenge_code']);
        exit;
    }
    $hash = hash('sha256', $challengeCode . $VERIFICATION_TOKEN . $ENDPOINT_URL);
    http_response_code(200);
    echo json_encode(['challengeResponse' => $hash]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    // Log the notification so we have a paper trail; we don't store user data,
    // so there's nothing else to do here.
    error_log('[ebay-deletion.php] notification: ' . substr($body, 0, 2000));
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
