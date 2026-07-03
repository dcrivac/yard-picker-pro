<?php
// Creates a Stripe Checkout Session for a credit pack and redirects the
// signed-in user to Stripe's hosted payment page.
//
//   GET checkout.php?pack=small   -> $5  for 30 credits
//   GET checkout.php?pack=large   -> $15 for 100 credits
//
// No Stripe SDK / composer dependency — plain REST calls via curl, same
// pattern as ebay-prices.php talking to eBay.

require_once __DIR__ . '/db.php';

$me = currentUser();
if (!$me) { http_response_code(401); echo 'Sign in first, then try again.'; exit; }

$PACKS = [
    'small' => ['credits' => 30,  'usd_cents' => 500,  'label' => '30 analyses'],
    'large' => ['credits' => 100, 'usd_cents' => 1500, 'label' => '100 analyses'],
];
$packKey = isset($_GET['pack']) ? $_GET['pack'] : '';
if (!isset($PACKS[$packKey])) { http_response_code(400); echo 'Unknown credit pack.'; exit; }
$pack = $PACKS[$packKey];

$secretKey = getenv('STRIPE_SECRET_KEY') ?: '';
if ($secretKey === '') { http_response_code(503); echo 'Payments are not configured yet.'; exit; }

$params = [
    'mode'                                  => 'payment',
    'success_url'                           => 'https://crivac.com/yp2.html?checkout=success',
    'cancel_url'                            => 'https://crivac.com/yp2.html?checkout=cancelled',
    'client_reference_id'                   => (string)$me['id'],
    'customer_email'                        => $me['email'],
    'line_items[0][quantity]'               => 1,
    'line_items[0][price_data][currency]'   => 'usd',
    'line_items[0][price_data][unit_amount]'=> $pack['usd_cents'],
    'line_items[0][price_data][product_data][name]' => 'Yard Picker Pro — ' . $pack['label'],
    'metadata[user_id]'                     => (string)$me['id'],
    'metadata[credits]'                     => (string)$pack['credits'],
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if ($code !== 200 || empty($data['url'])) {
    error_log('[checkout.php] Stripe session create failed (HTTP ' . $code . '): ' . substr((string)$resp, 0, 500));
    http_response_code(502);
    echo 'Could not start checkout. Please try again.';
    exit;
}

header('Location: ' . $data['url'], true, 303);
