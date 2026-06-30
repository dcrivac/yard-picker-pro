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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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
// Non-Primo prices, refreshed from /DesktopModules/pyp_api/api/PriceList/
// for locationCode=1264 (Chula Vista). Total = base + core + warranty.
$LKQ = [
    "A/C Compressor"=>86.05,"A/C Compressor Clutch"=>40.30,"A/C Condenser"=>65.10,
    "A/C Dryer"=>23.10,"A/C Evaporator"=>45.95,"A/C Hoses"=>27.30,
    "A/C Valve"=>22.10,"ABS Module"=>111.40,"Actuator"=>25.35,
    "Air Bag"=>94.25,"Air Cleaner"=>37.05,"Air Flow Meter"=>50.70,
    "Air Injection Pump"=>32.50,"Air Intake Tube"=>24.70,"Airbag Sensor"=>55.90,
    "Alternator"=>60.70,"Amplifier"=>46.15,"Axle Assembly Front"=>257.95,
    "Axle Assembly Rear"=>274.40,"Axle Housing"=>141.29,"Axle Shaft"=>55.35,
    "Back Glass Regulator (Electric)"=>62.39,"Back Glass Regulator (Manual)"=>38.99,"Battery"=>49.45,
    "Battery (Hybrid)"=>501.50,"Brake Caliper"=>38.45,"Brake Rotor"=>21.85,
    "Brake Rotor w/ Hub"=>45.90,"Bumper Cover Front"=>96.20,"Bumper Cover Rear"=>96.85,
    "Bumper Reinforcement Front"=>50.50,"Bumper Reinforcement Rear"=>69.30,"CV Axle Shaft"=>50.79,
    "Camshaft"=>84.00,"Carburetor"=>73.90,"Carrier Assembly"=>153.00,
    "Center Console"=>26.65,"Center Pillar"=>118.29,"Chassis Control Module"=>62.20,
    "Clock Spring"=>57.20,"Coil Pack"=>62.15,"Control Arm Lower"=>38.15,
    "Control Arm Upper"=>53.75,"Crankshaft"=>131.50,"Cylinder Head"=>111.15,
    "Dash Panel"=>97.50,"DC Converter (Hybrid)"=>174.85,"Decklid/Tailgate"=>110.75,
    "Differential"=>292.59,"Distributor"=>51.10,"Door Assembly Back"=>151.05,
    "Door Front"=>102.30,"Door Rear"=>101.00,"Drive Shaft"=>65.45,
    "ECU / PCM"=>95.35,"Electric Power Steering Pump"=>67.40,"Engine (Long Block)"=>468.89,
    "Exhaust Manifold"=>50.45,"Fan Clutch"=>26.65,"Fender"=>88.60,
    "Flywheel"=>45.30,"Front Bumper (Steel)"=>125.70,"Fuel Pump"=>60.25,
    "Fuel Pump (Direct Injection)"=>109.00,"Fuel Tank"=>45.50,"Fuse Box"=>33.80,
    "GPS / Navigation Screen"=>89.05,"Harmonic Balancer"=>63.05,"Headlight"=>54.60,
    "Heater Core"=>34.25,"Hood"=>119.85,"Hub Assembly"=>34.15,
    "Independent Rear Suspension"=>396.06,"Instrument Cluster"=>42.90,"Intake Manifold"=>70.60,
    "Intercooler"=>102.95,"Leaf Spring"=>47.60,"Mirror (Side View)"=>54.60,
    "Oil Pump"=>38.50,"Power Brake Booster"=>59.90,"Power Steering Pump"=>47.25,
    "Quarter Panel"=>176.80,"Radiator"=>70.45,"Radiator Core Support"=>93.45,
    "Radiator/Condenser Fan (Dual)"=>102.25,"Radiator/Condenser Fan (Single)"=>60.65,"Radio with Display"=>63.70,
    "Radio without Display"=>39.00,"Radio / Head Unit"=>66.29,"Rear Bumper (Steel)"=>125.70,
    "Ring and Pinion Gear"=>144.50,"Roof"=>163.15,"Running Board"=>41.65,
    "Seat (Front)"=>44.85,"Seat (Rear)"=>70.85,"Seat (Third Row)"=>85.79,
    "Shock Absorber"=>21.45,"Sliding Door Motor"=>64.99,"Spindle/Knuckle"=>61.55,
    "Stabilizer Bar"=>40.35,"Starter Motor"=>58.10,"Steering Column"=>79.75,
    "Steering Rack"=>84.10,"Strut Assembly"=>55.35,"Strut (Air)"=>68.70,
    "Suspension Crossmember"=>121.80,"Tail Light"=>42.90,"Taillight"=>42.90,
    "Temperature Control"=>48.10,"Throttle Body"=>74.55,"Torque Converter"=>307.99,
    "Transfer Case"=>241.90,"Transfer Case Motor"=>69.79,"Transmission (Auto)"=>255.55,
    "Transmission (Manual)"=>255.55,"Transmission Solenoid Pack"=>85.15,"Transmission Valve Body"=>126.50,
    "Turbocharger"=>129.60,"Water Pump"=>49.40,"Wheel (Aluminum)"=>70.05,
    "Wheel (Steel)"=>34.80,"Wheel / Rim"=>65.69,"Window Regulator Front (Electric)"=>46.65,
    "Window Regulator Front (Manual)"=>21.45,"Window Regulator Rear (Electric)"=>69.55,"Window Regulator"=>47.29,
    "Wiring Harness (Body)"=>79.00,"Wiring Harness (Dash)"=>45.50,"Wiring Harness (Engine)"=>70.00,
];

// ── EBAY MOTORS CATEGORY IDS ──────────────────────────────────────────────
// Required by eBay's Browse API alongside compatibility_filter. Mapping
// part names to specific sub-categories filters out unrelated listings
// (e.g. ECU lookups only see Engine Computers, not random electronics).
// Falls back to 6030 (Car & Truck Parts & Accessories) if not listed.
$PART_CATEGORIES = [
    // Engine internals + accessories
    "Engine (Long Block)"=>"33615","Cylinder Head"=>"33616","Camshaft"=>"33616",
    "Crankshaft"=>"33616","Harmonic Balancer"=>"33616","Flywheel"=>"33616",
    "Intake Manifold"=>"33616","Exhaust Manifold"=>"33616","Oil Pump"=>"33616",
    "Turbocharger"=>"33616","Water Pump"=>"33616",
    "Carburetor"=>"33591","Throttle Body"=>"33591","Intercooler"=>"33591",
    "Air Cleaner"=>"33591","Air Intake Tube"=>"33591","Air Injection Pump"=>"33591",
    "Fuel Pump"=>"33591","Fuel Pump (Direct Injection)"=>"33591","Fuel Tank"=>"33591",
    // Engine computers + electronics + sensors
    "ECU / PCM"=>"33596","Chassis Control Module"=>"33596",
    "Coil Pack"=>"33580","Distributor"=>"33580","Air Flow Meter"=>"33580",
    "Actuator"=>"33580","Fuse Box"=>"33580","Sliding Door Motor"=>"33580",
    "DC Converter (Hybrid)"=>"33580","Air Bag"=>"33580","Airbag Sensor"=>"33580",
    // Charging + starting
    "Alternator"=>"33543","Starter Motor"=>"33543","Battery"=>"33543","Battery (Hybrid)"=>"33543",
    // Cooling + A/C
    "Radiator"=>"33588","Radiator Core Support"=>"33588",
    "Radiator/Condenser Fan (Dual)"=>"33588","Radiator/Condenser Fan (Single)"=>"33588",
    "Heater Core"=>"33588","Fan Clutch"=>"33588",
    "A/C Compressor"=>"33588","A/C Compressor Clutch"=>"33588",
    "A/C Condenser"=>"33588","A/C Dryer"=>"33588","A/C Evaporator"=>"33588",
    "A/C Hoses"=>"33588","A/C Valve"=>"33588","Temperature Control"=>"33588",
    // Exterior body + glass
    "Hood"=>"33617","Fender"=>"33564","Quarter Panel"=>"33564",
    "Decklid/Tailgate"=>"33564","Roof"=>"33564","Dash Panel"=>"33564",
    "Running Board"=>"33564","Center Pillar"=>"33564",
    "Bumper Cover Front"=>"33709","Bumper Cover Rear"=>"33709",
    "Bumper Reinforcement Front"=>"33709","Bumper Reinforcement Rear"=>"33709",
    "Front Bumper (Steel)"=>"33709","Rear Bumper (Steel)"=>"33709",
    "Door Front"=>"33724","Door Rear"=>"33724","Door Assembly Back"=>"33724",
    "Window Regulator Front (Electric)"=>"33724","Window Regulator Front (Manual)"=>"33724",
    "Window Regulator Rear (Electric)"=>"33724","Window Regulator"=>"33724",
    "Back Glass Regulator (Electric)"=>"33724","Back Glass Regulator (Manual)"=>"33724",
    // Lighting + mirrors
    "Headlight"=>"33614","Tail Light"=>"33710","Taillight"=>"33710",
    "Mirror (Side View)"=>"33713",
    // Drivetrain + transmission
    "Transmission (Auto)"=>"33692","Transmission (Manual)"=>"33692",
    "Transmission Solenoid Pack"=>"33692","Transmission Valve Body"=>"33692",
    "Torque Converter"=>"33692","Drive Shaft"=>"33692",
    "CV Axle Shaft"=>"33692","Axle Shaft"=>"33692",
    "Axle Assembly Front"=>"33692","Axle Assembly Rear"=>"33692",
    "Axle Housing"=>"33692","Differential"=>"33692",
    "Transfer Case"=>"33692","Transfer Case Motor"=>"33692",
    "Carrier Assembly"=>"33692","Ring and Pinion Gear"=>"33692",
    // Suspension + steering + brakes
    "Control Arm Lower"=>"33586","Control Arm Upper"=>"33586",
    "Strut Assembly"=>"33586","Strut (Air)"=>"33586",
    "Shock Absorber"=>"33586","Stabilizer Bar"=>"33586",
    "Spindle/Knuckle"=>"33586","Hub Assembly"=>"33586",
    "Leaf Spring"=>"33586","Suspension Crossmember"=>"33586",
    "Independent Rear Suspension"=>"33586",
    "Steering Column"=>"33571","Steering Rack"=>"33571",
    "Power Steering Pump"=>"33571","Electric Power Steering Pump"=>"33571",
    "Clock Spring"=>"33571",
    "Brake Caliper"=>"33561","Brake Rotor"=>"33561","Brake Rotor w/ Hub"=>"33561",
    "Power Brake Booster"=>"33561","ABS Module"=>"33561",
    // Interior + audio
    "Seat (Front)"=>"6760","Seat (Rear)"=>"6760","Seat (Third Row)"=>"6760",
    "Center Console"=>"6753","Instrument Cluster"=>"6755",
    "GPS / Navigation Screen"=>"6755","Radio with Display"=>"6755",
    "Radio without Display"=>"6755","Radio / Head Unit"=>"6755",
    "Amplifier"=>"6755",
    // Wheels + wiring
    "Wheel (Aluminum)"=>"33567","Wheel (Steel)"=>"33567","Wheel / Rim"=>"33567",
    "Wiring Harness (Body)"=>"33580","Wiring Harness (Dash)"=>"33580",
    "Wiring Harness (Engine)"=>"33580",
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
                                    // Use eBay's structured compatibility_filter for vehicle
                                    // matching, not free-text keywords. Pass Year/Make/Model
                                    // separately so eBay only returns listings tagged as
                                    // fitting the specific vehicle. The q param becomes just
                                    // the cleaned part name (no vehicle words at all).
                                    $cleanPart = preg_replace('/\([^)]*\)/', '', $part['name']);
                                    $cleanPart = str_replace('/', ' ', $cleanPart);
                                    $cleanPart = trim(preg_replace('/\s+/', ' ', $cleanPart));
                                    $modelWords = explode(' ', trim($vehicle['model']));
                                    $compat = [
                                        'Year'  => $vehicle['year'],
                                        'Make'  => ucfirst(strtolower($vehicle['make'])),
                                        'Model' => $modelWords[0],
                                    ];
                                    $aiAvg = isset($part['ebayAvg']) ? (float)$part['ebayAvg'] : 0;
                                    $catId = isset($PART_CATEGORIES[$part['name']]) ? $PART_CATEGORIES[$part['name']] : '6030';
                                    $ebay  = ebaySearchMedian($cleanPart, $compat, $catId);
                                    if ($ebay) {
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
