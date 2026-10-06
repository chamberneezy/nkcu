<?php
/**
 * Lets the currently-logged-in user (identified by their own session
 * token, not the shared admin password) set their own real password —
 * used right after a first-time login with a temporary invite code, to
 * clear mustChangePassword. Also callable any time a user just wants to
 * change their password.
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
$newPassword = isset($body['newPassword']) ? (string) $body['newPassword'] : '';

$username = nkcu_verify_session($token);
if ($username === null) {
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Invalid or expired session']);
}

if (strlen($newPassword) < 8) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters']);
}

if (!nkcu_upsert_user($username, $newPassword, false)) {
    nkcu_auth_respond(500, ['ok' => false, 'error' => 'Could not update password']);
}

nkcu_auth_respond(200, ['ok' => true]);
