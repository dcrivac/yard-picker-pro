<?php
// Standalone eBay price lookup endpoint.
// GET ebay.php?q=2009+CHEVROLET+SILVERADO+ECU
// → {"avg":..., "low":..., "high":..., "count":...} or {"error":"..."}.

$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) { require_once $envFile; }
require_once __DIR__ . '/ebay-prices.php';

$allowedOrigins = ['https://crivac.com', 'https://www.crivac.com'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Content-Type: application/json');
header('Vary: Origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '' || strlen($q) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid q parameter']);
    exit;
}

// Optional vehicle compatibility passthrough so we can test the
// compatibility_filter path from curl.
$compat = null;
foreach (['Year' => 'year', 'Make' => 'make', 'Model' => 'model'] as $k => $param) {
    if (!empty($_GET[$param])) {
        $compat = $compat ?: [];
        $compat[$k] = trim($_GET[$param]);
    }
}

$result = ebaySearchMedian($q, $compat);
if (!$result) {
    echo json_encode(['error' => 'No data', 'q' => $q, 'compat' => $compat]);
    exit;
}
echo json_encode($result);
