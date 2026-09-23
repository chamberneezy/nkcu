<?php
/**
 * Issues a session token for one of the per-user accounts in
 * data/users.json. Not used by admin.html today — this exists so the
 * future iOS app has something to authenticate against. Accounts are
 * created via create-user.php.
 */

require_once __DIR__ . '/auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nkcu_auth_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$username = isset($body['username']) ? trim((string) $body['username']) : '';
$password = isset($body['password']) ? (string) $body['password'] : '';

if ($username === '' || $password === '') {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Missing username or password']);
}

if (!nkcu_verify_password($username, $password)) {
    // Same message whether the username doesn't exist or the password is
    // wrong, so this can't be used to enumerate valid usernames.
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Invalid credentials']);
}

$session = nkcu_create_session($username);
if ($session === null) {
    nkcu_auth_respond(500, ['ok' => false, 'error' => 'Could not create session']);
}

$user = nkcu_find_user($username);

nkcu_auth_respond(200, [
    'ok' => true,
    'username' => $username,
    'token' => $session['token'],
    'expiresAt' => $session['expiresAt'],
    'mustChangePassword' => !empty($user['mustChangePassword']),
    'hasSeenWelcome' => !empty($user['hasSeenWelcome']),
    'isBlocked' => !empty($user['isBlocked']),
]);
