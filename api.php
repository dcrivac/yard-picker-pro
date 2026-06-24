<?php
// Load secrets from gitignored file if present, otherwise rely on server env vars.
$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) { require $envFile; }
require_once __DIR__ . '/ebay-prices.php';
define('ANTHROPIC_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
// Daily Anthropic spend ceiling (USD) across ALL users — circuit breaker
// against a viral-traffic surprise bill. Approximate; based on token usage
// returned by Anthropic. Tune via env var; default $5/day.
define('DAILY_COST_CEILING_USD', (float)(getenv('DAILY_COST_CEILING_USD') ?: 5));

ini_set('max_execution_time', '180');
ini_set('max_input_time', '180');
ini_set('memory_limit', '256M');
ini_set('post_max_size', '20M');

// Generic failure response — log details server-side, don't leak to client.
function fail($status, $logMessage) {
    error_log('[api.php] ' . $logMessage);
    http_response_code($status);
    echo json_encode(['error' => ['message' => 'Request failed']]);
    exit;
}

// Per-day Anthropic cost tracking. File auto-rotates by date so old days
// drop off without manual cleanup. Race on concurrent writes is acceptable
// (a few cents of slop on a $5 ceiling).
function dailyCostFile() {
    return sys_get_temp_dir() . '/yp-cost-' . gmdate('Y-m-d') . '.txt';
}
function getDailyCost() {
    $f = dailyCostFile();
    return is_file($f) ? (float)trim(@file_get_contents($f)) : 0.0;
}
function recordCost($model, $usage) {
    if (!isset($usage['input_tokens'], $usage['output_tokens'])) return;
    // Approx public pricing per million tokens: Haiku $1/$5, Sonnet $3/$15.
    if (strpos($model, 'sonnet') !== false) { $pin = 3;  $pout = 15; }
    else                                    { $pin = 1;  $pout = 5;  }
    $cost = ($usage['input_tokens'] * $pin + $usage['output_tokens'] * $pout) / 1_000_000;
    $fp = @fopen(dailyCostFile(), 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        $cur = (float)stream_get_contents($fp);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, (string)($cur + $cost));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// CORS allowlist — only crivac.com origins may call this endpoint.
$allowedOrigins = ['https://crivac.com', 'https://www.crivac.com'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Content-Type: application/json');
header('Vary: Origin');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { fail(405, 'Method not allowed: ' . $_SERVER['REQUEST_METHOD']); }

if (ANTHROPIC_KEY === '') { fail(500, 'ANTHROPIC_API_KEY env var not set'); }

// Rate limit: 10 requests/minute per IP via tmpfile counter.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$bucket = sys_get_temp_dir() . '/yp-rl-' . hash('sha256', $ip);
$now = time();
$window = 60;
$limit = 10;
$entries = [];
if (is_file($bucket)) {
    $raw = @file_get_contents($bucket);
    if ($raw) {
        foreach (explode("\n", trim($raw)) as $ts) {
            if ($ts !== '' && (int)$ts > $now - $window) $entries[] = (int)$ts;
        }
    }
}
if (count($entries) >= $limit) {
    header('Retry-After: ' . $window);
    fail(429, 'Rate limit exceeded for ' . $ip);
}
$entries[] = $now;
@file_put_contents($bucket, implode("\n", $entries), LOCK_EX);

// Daily per-IP request cap. Auto-rotates by date. A real yard session is
// ~6-10 calls (1 OCR + 5 batches of 5 cars); 50/day per IP gives ~5-8
// sessions/day per user — enough for one person, hard to abuse.
$dailyBucket = sys_get_temp_dir() . '/yp-rl-day-' . hash('sha256', $ip) . '-' . gmdate('Y-m-d');
$dailyCount  = is_file($dailyBucket) ? (int)@file_get_contents($dailyBucket) : 0;
if ($dailyCount >= 50) {
    header('Retry-After: 86400');
    fail(429, 'Daily request limit exceeded for ' . $ip);
}
@file_put_contents($dailyBucket, $dailyCount + 1, LOCK_EX);

// Global daily cost ceiling — circuit breaker against viral-spike bills.
if (getDailyCost() >= DAILY_COST_CEILING_USD) {
    header('Retry-After: 3600');
    fail(503, 'Daily cost ceiling reached ($' . DAILY_COST_CEILING_USD . ')');
}

// Bound request body size (Content-Length is advisory; we re-check after read).
$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
if ($declared > 20000) { fail(413, 'Body too large (declared ' . $declared . ')'); }

$body = file_get_contents('php://input');
if (!$body) { fail(400, 'Empty body'); }
if (strlen($body) > 20000) { fail(413, 'Body too large (' . strlen($body) . ' bytes)'); }

$decoded = json_decode($body, true);
if (!$decoded) { fail(400, 'Invalid JSON'); }

// Validate request shape and model allowlist before paying upstream.
if (!isset($decoded['model']) || !is_string($decoded['model'])) { fail(400, 'Missing model'); }
$modelOk = false;
foreach (['claude-haiku-', 'claude-sonnet-'] as $prefix) {
    if (strncmp($decoded['model'], $prefix, strlen($prefix)) === 0) { $modelOk = true; break; }
}
if (!$modelOk) { fail(400, 'Disallowed model: ' . $decoded['model']); }
if (!isset($decoded['messages']) || !is_array($decoded['messages']) || count($decoded['messages']) === 0) {
    fail(400, 'Missing or empty messages');
}
if (isset($decoded['max_tokens']) && (!is_int($decoded['max_tokens']) || $decoded['max_tokens'] > 8192 || $decoded['max_tokens'] < 1)) {
    fail(400, 'max_tokens out of range: ' . var_export($decoded['max_tokens'], true));
}

// ── PYP CHULA VISTA PRICE LIST (Total Price) ─────────────────────────────
$LKQ = [
    "A/C Compressor"=>84.09,"A/C Compressor Clutch"=>40.29,"A/C Condenser"=>62.49,
    "A/C Dryer"=>23.09,"A/C Evaporator"=>43.99,"A/C Hoses"=>27.29,"A/C Valve"=>22.09,
    "ABS Module"=>106.19,"Actuator"=>23.39,"Air Bag"=>92.29,"Air Cleaner"=>37.69,
    "Air Flow Meter"=>51.99,"Air Injection Pump"=>32.49,"Air Intake Tube"=>24.69,
    "Airbag Sensor"=>55.89,"Alternator"=>59.39,"Amplifier"=>46.79,
    "Axle Assembly Front"=>297.59,"Axle Assembly Rear"=>292.59,"Axle Housing"=>141.29,
    "Axle Shaft"=>50.79,"Back Glass Regulator (Electric)"=>62.39,
    "Back Glass Regulator (Manual)"=>38.99,"Battery"=>44.49,"Battery (Hybrid)"=>452.99,
    "Brake Caliper"=>40.39,"Brake Rotor"=>21.19,"Brake Rotor w/ Hub"=>44.59,
    "Bumper Cover Front"=>93.59,"Bumper Cover Rear"=>93.59,
    "Bumper Reinforcement Front"=>51.79,"Bumper Reinforcement Rear"=>69.29,
    "CV Axle Shaft"=>50.79,"Camshaft"=>75.99,"Carburetor"=>76.49,
    "Carrier Assembly"=>154.42,"Center Console"=>27.29,"Center Pillar"=>118.29,
    "Chassis Control Module"=>63.49,"Clock Spring"=>58.49,"Coil Pack"=>62.79,
    "Control Arm Lower"=>38.79,"Control Arm Upper"=>51.79,"Crankshaft"=>131.29,
    "Cylinder Head"=>111.79,"Dash Panel"=>101.39,"DC Converter (Hybrid)"=>183.81,
    "Decklid/Tailgate"=>108.79,"Differential"=>292.59,"Distributor"=>52.39,
    "Door Assembly Back"=>138.69,"Door Front"=>108.79,"Door Rear"=>108.79,
    "Drive Shaft"=>66.09,"ECU / PCM"=>92.09,"Electric Power Steering Pump"=>74.49,
    "Engine (Long Block)"=>468.89,"Exhaust Manifold"=>49.79,"Fan Clutch"=>27.29,
    "Fender"=>86.64,"Flywheel"=>43.99,"Front Bumper (Steel)"=>128.29,
    "Fuel Pump"=>60.89,"Fuel Pump (Direct Injection)"=>101.19,"Fuel Tank"=>46.79,
    "Fuse Box"=>33.79,"GPS / Navigation Screen"=>79.29,"Harmonic Balancer"=>64.99,
    "Headlight"=>53.29,"Heater Core"=>34.89,"Hood"=>117.89,"Hub Assembly"=>34.79,
    "Independent Rear Suspension"=>396.06,"Instrument Cluster"=>40.29,
    "Intake Manifold"=>71.89,"Intercooler"=>102.29,"Leaf Spring"=>46.29,
    "Mirror (Side View)"=>53.29,"Oil Pump"=>37.99,"Power Brake Booster"=>57.29,
    "Power Steering Pump"=>47.89,"Quarter Panel"=>177.24,"Radiator"=>67.19,
    "Radiator Core Support"=>94.74,"Radiator/Condenser Fan (Dual)"=>106.79,
    "Radiator/Condenser Fan (Single)"=>61.29,"Radio with Display"=>66.29,
    "Radio without Display"=>40.29,"Radio / Head Unit"=>66.29,
    "Rear Bumper (Steel)"=>128.29,"Ring and Pinion Gear"=>151.39,"Roof"=>170.68,
    "Running Board"=>43.59,"Seat (Front)"=>59.79,"Seat (Rear)"=>71.49,
    "Seat (Third Row)"=>85.79,"Shock Absorber"=>20.79,"Sliding Door Motor"=>64.99,
    "Spindle/Knuckle"=>58.29,"Stabilizer Bar"=>40.99,"Starter Motor"=>59.39,
    "Steering Column"=>80.39,"Steering Rack"=>82.79,"Strut Assembly"=>53.39,
    "Strut (Air)"=>68.69,"Suspension Crossmember"=>121.79,"Tail Light"=>40.29,
    "Taillight"=>40.29,"Temperature Control"=>46.79,"Throttle Body"=>71.29,
    "Torque Converter"=>307.99,"Transfer Case"=>253.20,"Transfer Case Motor"=>69.79,
    "Transmission (Auto)"=>266.33,"Transmission (Manual)"=>266.33,
    "Transmission Solenoid Pack"=>87.09,"Transmission Valve Body"=>131.29,
    "Turbocharger"=>140.00,"Water Pump"=>49.39,"Wheel (Aluminum)"=>65.69,
    "Wheel (Steel)"=>33.49,"Wheel / Rim"=>65.69,
    "Window Regulator Front (Electric)"=>47.29,"Window Regulator Front (Manual)"=>22.09,
    "Window Regulator Rear (Electric)"=>62.39,"Window Regulator"=>47.29,
    "Wiring Harness (Body)"=>70.99,"Wiring Harness (Dash)"=>59.79,
    "Wiring Harness (Engine)"=>71.29
];

// Fuzzy match: find best LKQ price for a part name
function findLKQPrice($name, $lkq) {
    // Exact match first
    if (isset($lkq[$name])) return ['price'=>$lkq[$name],'matched'=>$name];
    $nu = strtoupper($name);
    $bestKey = null; $bestScore = 0;
    foreach ($lkq as $k => $v) {
        $ku = strtoupper($k);
        if (strpos($nu,$ku)!==false || strpos($ku,$nu)!==false) {
            $score = min(strlen($nu),strlen($ku));
            if ($score>$bestScore){$bestScore=$score;$bestKey=$k;}
        }
        $nw = preg_split('/[\s\/\(\)]+/',$nu);
        $kw = preg_split('/[\s\/\(\)]+/',$ku);
        $overlap = 0;
        foreach($nw as $w){ if(strlen($w)>3 && in_array($w,$kw)) $overlap++; }
        if ($overlap>0 && $overlap*10>$bestScore){$bestScore=$overlap*10;$bestKey=$k;}
    }
    return $bestKey ? ['price'=>$lkq[$bestKey],'matched'=>$bestKey] : ['price'=>50,'matched'=>'DEFAULT'];
}

// ── CALL ANTHROPIC ────────────────────────────────────────────────────────
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) { fail(502, 'cURL error: ' . $curlError); }
if (!$response)  { fail(502, 'Empty response from Anthropic (HTTP ' . $httpCode . ')'); }
if ($httpCode < 200 || $httpCode >= 300) {
    // Don't proxy upstream error messages — they reveal billing/quota state.
    fail(502, 'Anthropic HTTP ' . $httpCode . ': ' . substr($response, 0, 500));
}

// Tally this call's token cost toward the daily ceiling.
$parsedResp = json_decode($response, true);
if (isset($parsedResp['usage']) && isset($decoded['model'])) {
    recordCost($decoded['model'], $parsedResp['usage']);
}

// Build carIndex → vehicle map by parsing the "Car N: YYYY MAKE MODEL [Row X]"
// lines from the prompt. Used to construct eBay search queries per part.
$vehiclesByIndex = [];
if (isset($decoded['messages'][0]['content']) && is_string($decoded['messages'][0]['content'])) {
    if (preg_match_all('/^Car (\d+): (\d{4}) (\S+) (.+?)(?:\s+Row\s+\S+)?$/m', $decoded['messages'][0]['content'], $vm, PREG_SET_ORDER)) {
        foreach ($vm as $m) {
            $vehiclesByIndex[(int)$m[1]] = ['year' => $m[2], 'make' => $m[3], 'model' => trim($m[4])];
        }
    }
}

// ── INJECT LKQ + LIVE EBAY PRICES INTO RESPONSE ───────────────────────────
$resp = json_decode($response, true);
if ($resp && isset($resp['content'])) {
    foreach ($resp['content'] as &$block) {
        if ($block['type'] === 'text') {
            $text = $block['text'];
            // Find JSON array in response
            $s = strpos($text,'['); $e = strrpos($text,']');
            if ($s!==false && $e!==false) {
                $jsonStr = substr($text,$s,$e-$s+1);
                $cars = json_decode($jsonStr, true);
                if ($cars && is_array($cars)) {
                    foreach ($cars as &$car) {
                        if (isset($car['parts']) && is_array($car['parts'])) {
                            $carIdx = isset($car['carIndex']) ? (int)$car['carIndex'] : -1;
                            $vehicle = isset($vehiclesByIndex[$carIdx]) ? $vehiclesByIndex[$carIdx] : null;
                            foreach ($car['parts'] as &$part) {
                                $lk = findLKQPrice($part['name'], $LKQ);
                                $part['lkqPrice'] = round($lk['price'], 2);
                                $part['lkqMatched'] = $lk['matched'];

                                // Live eBay lookup. Skip the price override if eBay's
                                // median is < 40% of Claude's estimate — strong signal
                                // of "accessory bleed" (e.g. searching ECU returns lots
                                // of $20 ECU brackets and pin connectors). Falls back
                                // to the AI estimate in that case.
                                //
                                // Shipping is taken from eBay whenever available
                                // (independent of the price-override decision) since
                                // it's drawn from real fixed-price listings of the
                                // same query and is strictly better than any guess.
                                $part['ebaySource'] = 'ai_estimate';
                                if ($vehicle) {
                                    $query = $vehicle['year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model'] . ' ' . $part['name'];
                                    $ebay = ebaySearchMedian($query);
                                    if ($ebay) {
                                        $aiAvg = isset($part['ebayAvg']) ? (float)$part['ebayAvg'] : 0;
                                        if ($aiAvg < 1 || $ebay['avg'] >= $aiAvg * 0.4) {
                                            $part['ebayAvg'] = $ebay['avg'];
                                            $part['ebayLow'] = $ebay['low'];
                                            $part['ebayHigh'] = $ebay['high'];
                                            $part['ebaySource'] = 'ebay_' . $ebay['count'];
                                        }
                                        if (isset($ebay['shipping']) && $ebay['shipping'] !== null) {
                                            $part['shipping'] = $ebay['shipping'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                    // Replace text with updated JSON
                    $block['text'] = substr($text,0,$s).json_encode($cars).substr($text,$e+1);
                }
            }
        }
    }
    http_response_code($httpCode);
    echo json_encode($resp);
    exit;
}

http_response_code($httpCode);
echo $response;
