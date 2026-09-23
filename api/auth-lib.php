<?php
/**
 * Shared helpers for the per-user auth system used by future endpoints
 * (login.php, create-user.php, and eventually the iOS app's write
 * requests). Not wired into any of the existing admin.html endpoints —
 * those still use the single shared ADMIN_PASSWORD exactly as before.
 *
 * Not a public endpoint: including this file does nothing by itself,
 * but guard against it being requested directly anyway.
 */

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

define('NKCU_USERS_FILE', dirname(__DIR__) . '/data/users.json');
define('NKCU_SESSIONS_FILE', dirname(__DIR__) . '/data/sessions.json');
define('NKCU_SESSION_TTL_SECONDS', 60 * 60 * 24 * 30); // 30 days

function nkcu_auth_respond($status, $payload) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Locked read-modify-write against a JSON file holding a single object. */
function nkcu_json_update($path, $defaultRoot, callable $mutate) {
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }

    $size = filesize($path);
    $contents = $size > 0 ? fread($fh, $size) : '';
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        $data = $defaultRoot;
    }

    $data = $mutate($data);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $json);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function nkcu_json_read($path, $defaultRoot) {
    if (!file_exists($path)) {
        return $defaultRoot;
    }
    $contents = file_get_contents($path);
    $data = json_decode($contents, true);
    return is_array($data) ? $data : $defaultRoot;
}

function nkcu_find_user($username) {
    $data = nkcu_json_read(NKCU_USERS_FILE, ['users' => []]);
    $users = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];
    return isset($users[$username]) ? $users[$username] : null;
}

/**
 * Create or overwrite one user's password. Returns true on success.
 *
 * $mustChangePassword defaults to true: create-user.php is the "invite"
 * step, so whoever sets the password here (today, that's the admin
 * typing a temporary one) is expected to be replaced by the real user's
 * own choice on their first login — see change-password.php.
 */
function nkcu_upsert_user($username, $newPassword, $mustChangePassword = true) {
    if (!is_dir(dirname(NKCU_USERS_FILE))) {
        return false;
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return nkcu_json_update(NKCU_USERS_FILE, ['users' => new stdClass()], function ($data) use ($username, $hash, $now, $mustChangePassword) {
        if (!isset($data['users']) || !is_array($data['users'])) {
            $data['users'] = [];
        }
        $existing = isset($data['users'][$username]) ? $data['users'][$username] : null;
        $data['users'][$username] = [
            'passwordHash' => $hash,
            'createdAt' => $existing['createdAt'] ?? $now,
            'updatedAt' => $now,
            'mustChangePassword' => $mustChangePassword,
            'hasSeenWelcome' => $existing['hasSeenWelcome'] ?? false,
            'isBlocked' => $existing['isBlocked'] ?? false,
        ];
        if (empty($data['users'])) {
            $data['users'] = new stdClass();
        }
        return $data;
    });
}

/** Sets one boolean flag (mustChangePassword / hasSeenWelcome) on a user. */
function nkcu_set_user_flag($username, $key, $value) {
    return nkcu_json_update(NKCU_USERS_FILE, ['users' => new stdClass()], function ($data) use ($username, $key, $value) {
        if (!isset($data['users']) || !is_array($data['users']) || !isset($data['users'][$username])) {
            return $data;
        }
        $data['users'][$username][$key] = $value;
        $data['users'][$username]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        return $data;
    });
}

function nkcu_verify_password($username, $password) {
    $user = nkcu_find_user($username);
    if ($user === null || !isset($user['passwordHash'])) {
        return false;
    }
    return password_verify($password, $user['passwordHash']);
}

/** Issues a session token for username. Returns ['token' => ..., 'expiresAt' => ISO8601]. */
function nkcu_create_session($username) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + NKCU_SESSION_TTL_SECONDS);

    if (!is_dir(dirname(NKCU_SESSIONS_FILE))) {
        return null;
    }
    $ok = nkcu_json_update(NKCU_SESSIONS_FILE, ['sessions' => new stdClass()], function ($data) use ($token, $username, $expiresAt) {
        if (!isset($data['sessions']) || !is_array($data['sessions'])) {
            $data['sessions'] = [];
        }
        // Opportunistically drop expired sessions so the file doesn't grow forever.
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($data['sessions'] as $t => $s) {
            if (!isset($s['expiresAt']) || $s['expiresAt'] < $nowIso) {
                unset($data['sessions'][$t]);
            }
        }
        $data['sessions'][$token] = ['username' => $username, 'expiresAt' => $expiresAt];
        if (empty($data['sessions'])) {
            $data['sessions'] = new stdClass();
        }
        return $data;
    });

    return $ok ? ['token' => $token, 'expiresAt' => $expiresAt] : null;
}

/** Returns the username for a valid, non-expired token, or null. */
function nkcu_verify_session($token) {
    if (!is_string($token) || $token === '') {
        return null;
    }
    $data = nkcu_json_read(NKCU_SESSIONS_FILE, ['sessions' => []]);
    $sessions = isset($data['sessions']) && is_array($data['sessions']) ? $data['sessions'] : [];
    if (!isset($sessions[$token])) {
        return null;
    }
    $session = $sessions[$token];
    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    if (!isset($session['expiresAt']) || $session['expiresAt'] < $nowIso) {
        return null;
    }
    return $session['username'];
}

/**
 * For future protected endpoints: reads "Authorization: Bearer <token>",
 * verifies it, and returns the username — or responds 401 and exits.
 * Not called anywhere yet.
 */
function nkcu_require_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        nkcu_auth_respond(401, ['ok' => false, 'error' => 'Missing bearer token']);
    }
    $username = nkcu_verify_session(trim($m[1]));
    if ($username === null) {
        nkcu_auth_respond(401, ['ok' => false, 'error' => 'Invalid or expired token']);
    }
    return $username;
}
