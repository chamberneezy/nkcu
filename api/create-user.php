<?php
/**
 * Creates or resets one per-user account for the admin iOS app (the
 * "invite" step: the person gets a username + temporary password and
 * must choose their own on first login).
 *
 * Only callable by an admin account (NKCU_ADMIN_USERNAMES in auth-lib.php)
 * with its own session token: POST {"token": ..., "username": ..., "newPassword": ...}.
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

// Only a logged-in admin-app user (their own session token); there is
// no shared password any more. See nkcu_require_session in auth-lib.php.
$actingUsername = nkcu_require_admin($body['token'] ?? null);

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
