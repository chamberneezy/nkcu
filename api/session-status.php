<?php
/**
 * Returns the current flags (isBlocked, mustChangePassword,
 * hasSeenWelcome) for whoever holds this session token — used on every
 * app relaunch (after Face ID/Touch ID succeeds), not just at login, so
 * a block applied while the app is closed actually takes effect next
 * time it's opened instead of only ever being checked once.
 */

require_once __DIR__ . '/auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nkcu_auth_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$token = isset($body['token']) ? (string) $body['token'] : '';
$username = nkcu_verify_session($token);
if ($username === null) {
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Invalid or expired session']);
}

$user = nkcu_find_user($username);

nkcu_auth_respond(200, [
    'ok' => true,
    'username' => $username,
    'isBlocked' => !empty($user['isBlocked']),
    'mustChangePassword' => !empty($user['mustChangePassword']),
    'hasSeenWelcome' => !empty($user['hasSeenWelcome']),
]);
