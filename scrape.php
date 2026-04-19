<?php
ob_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
        CURLOPT_SSL_VERIFYPEER => false,
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

    // Method 1: alt text on vehicle images
    preg_match_all('/alt="(\d{4})\s+([A-Z][A-Z0-9\s\-]+?)\s+available for parts"/i', $html, $am);
    foreach ($am[1] as $i => $year) {
        $fullName = trim($am[2][$i]);
        $p        = explode(' ', $fullName, 2);
        $make     = strtoupper($p[0]);
        $model    = strtoupper(isset($p[1]) ? $p[1] : '');
        $key      = $year . '|' . $make . '|' . $model;
        if (!isset($seen[$key])) {
            $row  = '';
            $pos2 = strpos($html, $year . ' ' . $fullName);
            if ($pos2 !== false) {
                $ctx = substr($html, max(0, $pos2 - 50), 1200);
                if (preg_match('/\bRow[:\s><b]*([A-Z]\d+)/i', $ctx, $rm)) $row = $rm[1];
            }
            $seen[$key] = true;
            $vehicles[] = ['year'=>$year,'make'=>$make,'model'=>$model,'row'=>$row];
            $pageCount++;
        }
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