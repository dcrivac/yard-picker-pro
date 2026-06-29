<?php
// One-off fetcher for the PYP yard price list.
//
//   GET prices.php?slug=chula-vista-1264          -> JSON {part_name: total_price, ...}
//   GET prices.php?slug=chula-vista-1264&raw=1    -> raw HTML for parser development
//
// PYP is Cloudflare-protected and 403s most non-SiteGround IPs, so this
// runs on the server and we curl it from a laptop. Same UA + headers as
// scrape.php (which works in production), so no extra unblocking needed.

ob_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : 'chula-vista-1264';
$raw  = !empty($_GET['raw']);

if (!preg_match('/^[a-z0-9-]+$/i', $slug)) {
    ob_end_clean();
    echo json_encode(['error' => 'Invalid slug']);
    exit;
}

$url = 'https://www.pyp.com/prices/' . $slug . '/';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
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

if ($err || $code !== 200 || !$html) {
    ob_end_clean();
    echo json_encode(['error' => 'Fetch failed', 'code' => $code, 'curl_error' => $err]);
    exit;
}

if ($raw) {
    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// Parser. Adjusted once we see the actual HTML structure via &raw=1.
// Best-effort patterns for common formats.
$prices = [];

// Pattern: table rows with <td>NAME</td><td>$PRICE</td>
if (preg_match_all('/<td[^>]*>\s*([A-Za-z][A-Za-z0-9\/\(\)\s\.\-]{2,60}?)\s*<\/td>\s*<td[^>]*>\s*\$?([\d,]+\.\d{2})\s*<\/td>/i', $html, $m)) {
    foreach ($m[1] as $i => $name) {
        $clean = trim(preg_replace('/\s+/', ' ', $name));
        if ($clean !== '') $prices[$clean] = (float)str_replace(',', '', $m[2][$i]);
    }
}

ob_end_clean();
echo json_encode([
    'location'   => $slug,
    'prices'     => $prices,
    'count'      => count($prices),
    'fetched_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
