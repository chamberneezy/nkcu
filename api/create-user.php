<?php
/**
 * Creates or resets one per-user account for the future iOS app's login.
 * Gated by the same shared admin password admin.html already uses today
 * — this is the "invite" step: you run this once per person you want to
 * give access to, they get a username + password to log in with.
 *
 * Not used by admin.html and not exposed in any UI yet.
 */

require_once __DIR__ . '/auth-lib.php';

define('ADMIN_PASSWORD', 'croatia1971');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nkcu_auth_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (!isset($body['password']) || !hash_equals(ADMIN_PASSWORD, (string) $body['password'])) {
    nkcu_auth_respond(401, ['ok' => false, 'error' => 'Falsches Passwort']);
}

$username = isset($body['username']) ? trim((string) $body['username']) : '';
$newPassword = isset($body['newPassword']) ? (string) $body['newPassword'] : '';

if (!preg_match('/^[a-zA-Z0-9._-]{2,64}$/', $username)) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Invalid username (2-64 chars, letters/digits/._- only)']);
}

if (strlen($newPassword) < 8) {
    nkcu_auth_respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters']);
}

if (!nkcu_upsert_user($username, $newPassword)) {
    nkcu_auth_respond(500, ['ok' => false, 'error' => 'Could not save user']);
}

nkcu_auth_respond(200, ['ok' => true, 'username' => $username]);
