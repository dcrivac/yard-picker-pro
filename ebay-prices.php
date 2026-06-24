<?php
// Force shortest round-trip-safe float representation so json_encode doesn't
// emit "28.989999999999998..." for what should print as 28.99 on hosts where
// the default serialize_precision is set high (e.g. SiteGround).
ini_set('serialize_precision', '-1');

// eBay Browse API helper: cached OAuth token + median price lookup.
//
// Public surface: ebaySearchMedian($query) returns
//   ['avg' => float, 'low' => float, 'high' => float, 'count' => int]
// or null if the search fails or returns too few results.
//
// Caches the OAuth token (2h lifetime) and each query result (24h) in the
// system temp dir to stay well under eBay's default 5000 calls/day quota.

function ebayGetToken() {
    $cacheFile = sys_get_temp_dir() . '/yp-ebay-token.json';
    if (is_file($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $clientId     = getenv('EBAY_CLIENT_ID') ?: '';
    $clientSecret = getenv('EBAY_CLIENT_SECRET') ?: '';
    if ($clientId === '' || $clientSecret === '') {
        error_log('[ebay-prices.php] EBAY_CLIENT_ID / EBAY_CLIENT_SECRET not set');
        return null;
    }

    $ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=' . urlencode('https://api.ebay.com/oauth/api_scope'),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log('[ebay-prices.php] token fetch failed (HTTP ' . $code . ') ' . $err . ' body=' . substr((string)$resp, 0, 300));
        return null;
    }
    $data = json_decode($resp, true);
    if (!$data || !isset($data['access_token'])) return null;
    $data['expires_at'] = time() + (int)($data['expires_in'] ?? 7200);
    @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    return $data['access_token'];
}

function ebaySearchMedian($query) {
    $query = trim($query);
    if ($query === '') return null;

    $key       = hash('sha256', strtolower($query));
    $cacheFile = sys_get_temp_dir() . '/yp-ebay-' . $key . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    $token = ebayGetToken();
    if (!$token) return null;

    $url = 'https://api.ebay.com/buy/browse/v1/item_summary/search?' . http_build_query([
        'q'      => $query,
        'filter' => 'conditions:{USED|FOR_PARTS_OR_NOT_WORKING},buyingOptions:{FIXED_PRICE}',
        'limit'  => 50,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'X-EBAY-C-MARKETPLACE-ID: EBAY_US',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log('[ebay-prices.php] search failed (HTTP ' . $code . ') for: ' . $query);
        return null;
    }

    $data  = json_decode($resp, true);
    $items = isset($data['itemSummaries']) ? $data['itemSummaries'] : [];
    $prices = [];
    $shipPrices = [];
    foreach ($items as $item) {
        if (isset($item['price']['value']) && is_numeric($item['price']['value'])) {
            $p = (float)$item['price']['value'];
            // Strip junk: $1 listings, $9999 buy-it-now placeholders.
            if ($p >= 5 && $p <= 10000) $prices[] = $p;
        }
        // Extract real shipping cost when seller fixed-priced it. Skip
        // CALCULATED (depends on destination) and local-pickup-only listings.
        if (!empty($item['shippingOptions'][0]['shippingCost']['value'])
            && is_numeric($item['shippingOptions'][0]['shippingCost']['value'])) {
            $sp = (float)$item['shippingOptions'][0]['shippingCost']['value'];
            if ($sp >= 1 && $sp <= 500) $shipPrices[] = $sp;
        }
    }
    if (count($prices) < 3) return null;
    sort($prices);
    $n      = count($prices);
    $median = ($n % 2) ? $prices[intdiv($n, 2)] : ($prices[$n/2 - 1] + $prices[$n/2]) / 2;
    $low    = $prices[(int)floor($n * 0.10)];
    $high   = $prices[(int)floor($n * 0.90)];

    // Shipping median (null if not enough fixed-price listings included it).
    $shipMedian = null;
    if (count($shipPrices) >= 3) {
        sort($shipPrices);
        $sn = count($shipPrices);
        $shipMedian = ($sn % 2)
            ? $shipPrices[intdiv($sn, 2)]
            : ($shipPrices[$sn/2 - 1] + $shipPrices[$sn/2]) / 2;
    }

    $result = [
        'avg'      => round($median, 2),
        'low'      => round($low, 2),
        'high'     => round($high, 2),
        'shipping' => $shipMedian !== null ? round($shipMedian, 2) : null,
        'count' => $n,
    ];
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    return $result;
}
