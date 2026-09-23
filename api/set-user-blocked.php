<?php
/**
 * Admin-only toggle: blocks or unblocks one user's access to the app.
 * A blocked user sees a gate screen instead of the app on every launch
 * (checked both at login and on every biometric unlock via
 * session-status.php) until this is called again with blocked=false.
 *
 * Gated by the same shared admin password as create-user.php.
 */

require_once __DIR__ . '/auth-lib.php';

define('ADMIN_PASSWORD', 'croatia1971');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nkcu_auth_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (!isset($body['password']) || !hash_equals(ADMIN_PASSWORD, (string) $body['password'])) {
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Falsches Passwort']);
}

$username = isset($body['username']) ? trim((string) $body['username']) : '';
$blocked = !empty($body['blocked']);

if ($username === '' || nkcu_find_user($username) === null) {
    nkcu_auth_respond(404, ['ok' => false, 'error' => 'User not found']);
}

nkcu_set_user_flag($username, 'isBlocked', $blocked);
nkcu_auth_respond(200, ['ok' => true, 'username' => $username, 'isBlocked' => $blocked]);
