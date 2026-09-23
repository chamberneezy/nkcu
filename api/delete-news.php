<?php
/**
 * Removes one item from data/news.json. Called by the nkcu_ios admin app's
 * News tab; there is no web UI for this.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/apns.php';

define('ADMIN_PASSWORD', 'croatia1971');
define('DATA_FILE', dirname(__DIR__) . '/data/news.json');

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (!isset($body['password']) || !hash_equals(ADMIN_PASSWORD, (string) $body['password'])) {
    respond(401, ['ok' => false, 'error' => 'Falsches Passwort']);
}

$actingUsername = isset($body['token']) ? nkcu_verify_session((string) $body['token']) : null;

$id = isset($body['id']) ? trim((string) $body['id']) : '';
if ($id === '') {
    respond(400, ['ok' => false, 'error' => 'Missing id']);
}

if (!is_file(DATA_FILE)) {
    respond(404, ['ok' => false, 'error' => 'No data file yet']);
}

$deletedTitle = null;
$ok = nkcu_json_update(DATA_FILE, ['items' => []], function ($data) use ($id, &$deletedTitle) {
    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
        return $data;
    }
    $data['items'] = array_values(array_filter($data['items'], function ($item) use ($id, &$deletedTitle) {
        if (isset($item['id']) && $item['id'] === $id) {
            $deletedTitle = $item['de']['title'] ?? $id;
            return false;
        }
        return true;
    }));
    return $data;
});

if (!$ok) {
    respond(500, ['ok' => false, 'error' => 'Could not update data file']);
}

if ($deletedTitle === null) {
    respond(404, ['ok' => false, 'error' => 'News item not found']);
}

nkcu_notify_admin_of_change($actingUsername, 'News Deleted', $deletedTitle);

respond(200, ['ok' => true]);
