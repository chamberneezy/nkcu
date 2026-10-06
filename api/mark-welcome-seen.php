<?php
/**
 * Marks that the currently-logged-in user has completed the one-time
 * welcome screen, so it never shows again — even after a reinstall or a
 * new device, since this is tracked server-side per account rather than
 * locally on the phone.
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

nkcu_set_user_flag($username, 'hasSeenWelcome', true);
nkcu_auth_respond(200, ['ok' => true]);
