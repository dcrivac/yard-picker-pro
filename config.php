<?php
// Single source of truth for where the app is served from.
//
// Set APP_ORIGIN in .env.php to the scheme + host the app lives on, with no
// trailing slash. Everything that needs an absolute URL (CORS allowlists,
// Stripe redirect URLs, password-reset links, the eBay deletion endpoint)
// derives from it, so moving hosts is a one-line config change.

$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) { require_once $envFile; }

if (!defined('APP_ORIGIN')) {
    define('APP_ORIGIN', rtrim(getenv('APP_ORIGIN') ?: 'https://yardpicker.crivac.com', '/'));
}

// Browser origins allowed to call the PHP endpoints. The frontend uses
// same-origin relative URLs, so this is normally just APP_ORIGIN.
function allowedOrigins() {
    return [APP_ORIGIN];
}
