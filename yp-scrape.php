<?php
ob_start();
header('Content-Type: application/json');
header('Vary: Origin');
// Defeat SiteGround's CDN caching this dynamic response as a static asset.
// The .htaccess Cache-Control headers are not respected by their edge.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$allowedOrigins = ['https://crivac.com', 'https://www.crivac.com'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

$url  = isset($_GET['url'])  ? trim($_GET['url'])  : '';
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$log  = [];

if (!$url && !$slug) {
    ob_end_clean();
    echo json_encode(['error'=>'No URL provided','log'=>[]]);
    exit;
}

$log[] = '▶ Fetching inventory for: ' . $url;

// Use slug sent from browser if available
$locationSlug = $slug;

// Otherwise extract from URL
if (!$locationSlug && $url) {
    $needle = '/inventory/';
    $pos = strpos($url, $needle);
    if ($pos !== false) {
        $after = substr($url, $pos + strlen($needle));
        $locationSlug = rtrim(strtok($after, '/?#'), '/');
    }
}

$log[] = '▶ Yard: ' . $locationSlug;

if (!$locationSlug) {
    ob_end_clean();
    echo json_encode(['error'=>'Could not extract location slug','log'=>$log]);
    exit;
}

$baseUrl  = 'https://www.pyp.com/inventory/' . $locationSlug;
$vehicles = [];
$seen     = [];
$page     = 1;

while ($page <= 20) {
    $pageUrl = $baseUrl . '/' . ($page > 1 ? '?page=' . $page : '');

    $ch = curl_init($pageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
        CURLOPT_HTTPHEADER     => ['Accept: text/html', 'Accept-Language: en-US,en;q=0.9'],
        CURLOPT_ENCODING       => '',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)         { $log[] = 'cURL error: ' . $err; break; }
    if ($code != 200) { $log[] = 'HTTP ' . $code . ' stopping'; break; }

    $pageCount = 0;

    // Method 1: anchor on each pypvi_resultRow div, extract row + vehicle
    // from that one row's HTML. The Row tag appears BEFORE the alt text
    // in the current PYP markup, so the old "search forward from alt text"
    // approach missed every row. Pure-digit rows like "71" are now common
    // (Chula Vista); the regex also handles legacy letter+digit rows like "L9".
    preg_match_all('/<div class="pypvi_resultRow"[^>]*>/i', $html, $starts, PREG_OFFSET_CAPTURE);
    foreach ($starts[0] as $i => $start) {
        $pos = $start[1];
        $next = isset($starts[0][$i + 1]) ? $starts[0][$i + 1][1] : $pos + 3000;
        $sec = substr($html, $pos, $next - $pos);
        if (!preg_match('/alt="(\d{4})\s+([A-Z][A-Z0-9\s\-]+?)\s+available for parts"/i', $sec, $am)) continue;
        $year     = $am[1];
        $fullName = trim($am[2]);
        $p        = explode(' ', $fullName, 2);
        $make     = strtoupper($p[0]);
        $model    = strtoupper(isset($p[1]) ? $p[1] : '');
        $key      = $year . '|' . $make . '|' . $model;
        if (isset($seen[$key])) continue;
        $row = '';
        if (preg_match('/Row:?[\s<>\/b]+([A-Z]?\d+)/i', $sec, $rm)) $row = $rm[1];
        $seen[$key] = true;
        $vehicles[] = ['year'=>$year,'make'=>$make,'model'=>$model,'row'=>$row];
        $pageCount++;
    }

    // Method 2: Compatible Parts links fallback
    preg_match_all('/year=(\d{4})&(?:amp;)?make=([^&]+)&(?:amp;)?model=([^"\'>\n&]+)/i', $html, $cpm);
    foreach ($cpm[1] as $i => $year) {
        $make  = strtoupper(html_entity_decode(urldecode(trim($cpm[2][$i]))));
        $model = strtoupper(html_entity_decode(urldecode(trim($cpm[3][$i]))));
        $key   = $year . '|' . $make . '|' . $model;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $vehicles[] = ['year'=>$year,'make'=>$make,'model'=>$model,'row'=>''];
            $pageCount++;
        }
    }

    $hasNext = strpos($html, '?page=' . ($page + 1)) !== false || strpos($html, 'Next Page') !== false;
    if (!$hasNext || $pageCount === 0) break;
    $page++;
    usleep(250000);
}

$log[] = 'DONE: ' . count($vehicles) . ' vehicles';
ob_end_clean();
echo json_encode(['vehicles'=>$vehicles,'pages'=>$page,'log'=>$log]);