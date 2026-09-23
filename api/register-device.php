<?php
/**
 * Stores one user's APNs device push token, sent by the iOS app right
 * after it's granted notification permission. Authenticated with the
 * user's own session token (not the shared admin password) — this is
 * per-device, per-account data, not an admin action.
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
$deviceToken = isset($body['deviceToken']) ? trim((string) $body['deviceToken']) : '';

$username = nkcu_verify_session($token);
if ($username === null) {
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Invalid or expired session']);
}

if (!preg_match('/^[0-9a-fA-F]{32,256}$/', $deviceToken)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid device token']);
}

nkcu_set_user_flag($username, 'apnsDeviceToken', $deviceToken);
nkcu_auth_respond(200, ['ok' => true]);
