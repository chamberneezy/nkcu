<?php
/**
 * Sends push notifications via Apple's APNs HTTP/2 API, using a token-
 * based (.p8) auth key — no certificates to renew, just one key that
 * doesn't expire. Fully built and ready to go; everything below is a
 * safe no-op (nkcu_apns_configured() returns false) until the three
 * config values below are filled in from the Apple Developer portal:
 * Certificates, Identifiers & Profiles > Keys > (+) > check "Apple
 * Push Notifications service (APNs)" > download the .p8 file ONCE
 * (Apple won't let you re-download it) and note the Key ID + Team ID.
 *
 * Only mbreljak gets notified about other admins' changes — see
 * NKCU_NOTIFY_USERNAME below and how save-goal.php/save-squad.php call
 * nkcu_notify_admin_of_change() after a successful write.
 */

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth-lib.php';

// ── Fill these in once you have the APNs key ──────────────────────────
define('APNS_KEY_ID', 'N79F8DM35N');
define('APNS_TEAM_ID', 'GRZRJ88LA9');
define('APNS_KEY_PATH', __DIR__ . '/private/AuthKey.p8');    // uploaded here, outside any public web path if possible
define('APNS_BUNDLE_ID', 'chamberneezy.nkcu-ios');           // must match PRODUCT_BUNDLE_IDENTIFIER in the Xcode project
// All our TestFlight/App Store builds are Distribution-signed, which Xcode
// always stamps with aps-environment=production regardless of what the
// source entitlements file says (verified directly against a shipped IPA)
// — so this must be false, never true, for push to actually reach devices.
define('APNS_USE_SANDBOX', false);
// ───────────────────────────────────────────────────────────────────────

// Only this account receives push notifications about OTHER admins'
// changes — per-user preference isn't needed since only one person
// (the club's actual admin) asked for this.
define('NKCU_NOTIFY_USERNAME', 'mbreljak');

function nkcu_apns_configured() {
    return APNS_KEY_ID !== '' && APNS_TEAM_ID !== '' && is_readable(APNS_KEY_PATH);
}

/**
 * Signs a fresh APNs auth JWT (ES256) from the .p8 key. APNs tokens are
 * cheap to mint and short-lived by convention; we just make a new one
 * per request rather than caching, since push volume here is tiny.
 */
function nkcu_apns_make_jwt() {
    $header = ['alg' => 'ES256', 'kid' => APNS_KEY_ID];
    $claims = ['iss' => APNS_TEAM_ID, 'iat' => time()];

    $b64 = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $headerPart = $b64(json_encode($header));
    $claimsPart = $b64(json_encode($claims));
    $signingInput = $headerPart . '.' . $claimsPart;

    $privateKeyPem = file_get_contents(APNS_KEY_PATH);
    $pkey = openssl_pkey_get_private($privateKeyPem);
    if ($pkey === false) {
        return null;
    }

    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return null;
    }

    // openssl_sign() produces a DER-encoded signature; JWT ES256 needs
    // the raw concatenated (r || s) 64-byte form instead.
    $derToRS = function ($der) {
        $offset = 2;
        $len = ord($der[1]);
        if ($len & 0x80) { $offset += ($len & 0x7f); }

        $seq = substr($der, $offset);
        $pos = 0;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $pos += 1; // skip 0x02 INTEGER tag
            $partLen = ord($seq[$pos]);
            $pos += 1;
            $part = substr($seq, $pos, $partLen);
            $part = ltrim($part, "\x00");
            $parts[] = str_pad($part, 32, "\x00", STR_PAD_LEFT);
            $pos += $partLen;
        }
        return $parts[0] . $parts[1];
    };

    $rs = $derToRS($signature);
    return $signingInput . '.' . $b64($rs);
}

/**
 * Sends one push to a single device token. Returns true on success
 * (APNs responded 200), false otherwise — never throws, since a failed
 * notification should never take down the write endpoint that triggered it.
 */
function nkcu_apns_send($deviceToken, $title, $body) {
    if (!nkcu_apns_configured()) {
        return false;
    }

    $jwt = nkcu_apns_make_jwt();
    if ($jwt === null) {
        return false;
    }

    $host = APNS_USE_SANDBOX
        ? 'https://api.sandbox.push.apple.com'
        : 'https://api.push.apple.com';

    $payload = json_encode([
        'aps' => [
            'alert' => ['title' => $title, 'body' => $body],
            'sound' => 'default',
        ],
    ]);

    $ch = curl_init("$host/3/device/$deviceToken");
    curl_setopt_array($ch, [
        // APNs' provider API requires HTTP/2 — there's no HTTP/1.1
        // fallback worth having here, so just require it outright.
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . APNS_BUNDLE_ID,
            'apns-push-type: alert',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

/**
 * The one function save-goal.php/save-squad.php actually call: notifies
 * NKCU_NOTIFY_USERNAME, unless they're the one who made the change
 * (nothing worse than getting pinged about your own actions), and only
 * if they have a registered device and APNs is configured.
 */
function nkcu_notify_admin_of_change($actingUsername, $title, $body) {
    // No attributable actor (e.g. a request from admin.html, which has
    // no per-user login and never sends a token) — treat conservatively
    // as "probably the admin themselves" rather than notifying on an
    // unknown action.
    if ($actingUsername === null || $actingUsername === NKCU_NOTIFY_USERNAME) {
        return;
    }
    $user = nkcu_find_user(NKCU_NOTIFY_USERNAME);
    $deviceToken = $user['apnsDeviceToken'] ?? null;
    if (!$deviceToken) {
        return;
    }
    nkcu_apns_send($deviceToken, $title, $body);
}
