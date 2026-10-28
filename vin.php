<?php
// VIN decode and model lookup through NHTSA vPIC (free, no API key).
//
//   GET vin.php?vin=1HGCM82633A004352  -> {"vehicle":{vin,year,make,model,trim,engine,drive,transmission,body}}
//   GET vin.php?make=HONDA&year=2008   -> {"models":["ACCORD","CIVIC",...]}
//
// Results are cached on disk (a VIN's decode never changes), and calls are
// rate limited per IP so this can't be used as an open vPIC proxy.

require_once __DIR__ . '/vin-lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// 30 requests/minute per IP, same file-counter approach as api.php.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$bucket = sys_get_temp_dir() . '/yp-vin-rl-' . hash('sha256', $ip);
$now = time();
$entries = [];
if (is_file($bucket)) {
    foreach (explode("\n", trim((string)@file_get_contents($bucket))) as $ts) {
        if ($ts !== '' && (int)$ts > $now - 60) $entries[] = (int)$ts;
    }
}
if (count($entries) >= 30) { header('Retry-After: 60'); out(429, ['error' => 'Too many lookups. Wait a minute.']); }
$entries[] = $now;
@file_put_contents($bucket, implode("\n", $entries), LOCK_EX);

function vpicGet($path) {
    $ch = curl_init('https://vpic.nhtsa.dot.gov/api/vehicles/' . $path . (strpos($path, '?') === false ? '?' : '&') . 'format=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) { error_log('[vin.php] vPIC HTTP ' . $code . ' for ' . $path); return null; }
    $d = json_decode($resp, true);
    return (is_array($d) && isset($d['Results']) && is_array($d['Results'])) ? $d['Results'] : null;
}

function cacheFile($key) { return sys_get_temp_dir() . '/yp-vpic-' . hash('sha256', $key) . '.json'; }

// ── VIN DECODE ──────────────────────────────────────────────────
if (isset($_GET['vin'])) {
    $vin = vinNormalize($_GET['vin']);
    if (!vinFormatOk($vin))      out(400, ['error' => 'A VIN is 17 letters and numbers (no I, O or Q).']);
    if (!vinCheckDigitOk($vin))  out(400, ['error' => 'That VIN fails its check digit. One character is probably misread.']);

    $cf = cacheFile('vin:' . $vin);
    if (is_file($cf)) {
        $v = json_decode((string)@file_get_contents($cf), true);
        if (is_array($v)) out(200, ['vehicle' => $v]);
    }

    $rows = vpicGet('DecodeVinValues/' . $vin);
    if (!$rows) out(502, ['error' => 'VIN lookup is unavailable right now. Enter year, make and model instead.']);
    $v = vinVehicleFromVpic($vin, $rows[0]);
    if (!$v) out(404, ['error' => 'No vehicle found for that VIN. Enter year, make and model instead.']);

    @file_put_contents($cf, json_encode($v), LOCK_EX);
    out(200, ['vehicle' => $v]);
}

// ── MODELS FOR MAKE + YEAR (manual entry autocomplete) ───────────
if (isset($_GET['make'], $_GET['year'])) {
    $make = strtoupper(trim($_GET['make']));
    $year = trim($_GET['year']);
    if (!preg_match('/^[A-Z0-9 \-]{2,30}$/', $make) || !preg_match('/^(19[5-9]\d|20\d\d)$/', $year)) {
        out(400, ['error' => 'Invalid make or year']);
    }

    $cf = cacheFile('models:' . $make . ':' . $year);
    if (is_file($cf) && filemtime($cf) > time() - 30 * 86400) {
        $m = json_decode((string)@file_get_contents($cf), true);
        if (is_array($m)) out(200, ['models' => $m]);
    }

    $rows = vpicGet('GetModelsForMakeYear/make/' . rawurlencode($make) . '/modelyear/' . $year);
    if ($rows === null) out(502, ['error' => 'Model lookup unavailable']);
    $models = [];
    foreach ($rows as $r) {
        if (!empty($r['Model_Name'])) $models[strtoupper(trim($r['Model_Name']))] = true;
    }
    $models = array_keys($models);
    sort($models);

    @file_put_contents($cf, json_encode($models), LOCK_EX);
    out(200, ['models' => $models]);
}

out(400, ['error' => 'Pass vin, or make and year']);
