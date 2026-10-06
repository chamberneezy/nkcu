<?php
/**
 * Read-only mirror of the four public data files, with a CORS header, so the
 * GitHub Pages preview and localhost can show the live results/table/news
 * (see window.NKCU_DATA in assets/js/site.js). Nginx serves data/*.json
 * directly and ignores .htaccess, so the header can't be added there.
 *
 *   data-feed.php/spiele.json      (path style — what the pages request)
 *   data-feed.php?f=spiele.json
 *
 * Only these four files, never users/sessions/fan_devices. Never writes.
 */

$allowed = ['news.json', 'spiele.json', 'uzwil4.json', 'live_squad.json'];

$name = isset($_SERVER['PATH_INFO']) ? basename($_SERVER['PATH_INFO']) : (isset($_GET['f']) ? basename($_GET['f']) : '');

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    exit;
}

// Works in both layouts: flat (this file next to data/) and api/ (data/ one level up).
$dir = is_dir(__DIR__ . '/data') ? __DIR__ . '/data' : dirname(__DIR__) . '/data';
$path = $dir . '/' . $name;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
readfile($path);
