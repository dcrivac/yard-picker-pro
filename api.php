<?php
define('ANTHROPIC_KEY', 'YOUR_API_KEY_HERE');

ini_set('max_execution_time', '180');
ini_set('max_input_time', '180');
ini_set('memory_limit', '256M');
ini_set('post_max_size', '20M');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>['message'=>'Method not allowed']]); exit; }

$body = file_get_contents('php://input');
if (!$body) { http_response_code(400); echo json_encode(['error'=>['message'=>'Empty body']]); exit; }

$decoded = json_decode($body, true);
if (!$decoded) { http_response_code(400); echo json_encode(['error'=>['message'=>'Invalid JSON']]); exit; }

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

if ($curlError) { http_response_code(500); echo json_encode(['error'=>['message'=>'cURL: '.$curlError]]); exit; }
if (!$response) { http_response_code(500); echo json_encode(['error'=>['message'=>'Empty response from Anthropic']]); exit; }

// ── INJECT CORRECT LKQ PRICES INTO RESPONSE ───────────────────────────────
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
                            foreach ($car['parts'] as &$part) {
                                $result = findLKQPrice($part['name'], $LKQ);
                                $part['lkqPrice'] = $result['price'];
                                $part['lkqMatched'] = $result['matched'];
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