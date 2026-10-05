<?php
/**
 * Called by the public fan app (no login), iOS or Android. Two request
 * shapes:
 *
 *   { deviceToken, platform: "ios"|"android", lang: "de"|"hr",
 *     prefs: {live, goals, lineup, liveActivity?}, startToken? } — device + notification
 *     settings; startToken is the Live Activity push-to-start token
 *     (iOS only — Android ignores it, there's no Live Activity there)
 *   { activityToken, matchId }      — a running Live Activity's own
 *                                     update token, so goals reach it
 *                                     (iOS only)
 *
 * platform defaults to "ios" when omitted, so the existing iOS build
 * (which never sends it) keeps working unchanged. Only a device token,
 * platform and a few booleans are stored — nothing personal.
 * liveActivity is the Live-Widget switch; older app versions don't send
 * it, and fan-push.php then falls back to "live".
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/fan-push.php';

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_apns_token($t) {
    return is_string($t) && preg_match('/^[0-9a-fA-F]{32,256}$/', $t);
}

/** FCM registration tokens have no fixed alphabet. Reject only empty, tiny, or whitespace. */
function is_fcm_token($t) {
    return is_string($t) && strlen($t) >= 20 && strlen($t) <= 4096 && !preg_match('/\s/', $t);
}

function is_device_token($t, $platform) {
    return $platform === 'android' ? is_fcm_token($t) : is_apns_token($t);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (isset($body['activityToken'])) {
    $token = strtolower((string) $body['activityToken']);
    $matchId = isset($body['matchId']) ? trim((string) $body['matchId']) : '';
    if (!is_apns_token($token) || !preg_match('/^[A-Za-z0-9-]{1,200}$/', $matchId)) {
        respond(400, ['ok' => false, 'error' => 'Invalid activity token or matchId']);
    }
    nkcu_fan_update_store(function (&$store) use ($token, $matchId) {
        $list = $store['activities'][$matchId] ?? [];
        if (!in_array($token, $list, true)) { $list[] = $token; }
        $store['activities'][$matchId] = array_slice($list, -5000);
    });
    respond(200, ['ok' => true]);
}

// The installed Android build omits "platform" (serialization default).
// An FCM token is not hex, so treat a non-APNs token as Android.
$rawToken = (string) ($body['deviceToken'] ?? '');
$stated = $body['platform'] ?? null;
if ($stated === 'android' || ($stated === null && !is_apns_token($rawToken))) {
    $platform = 'android';
    $deviceToken = $rawToken;
} else {
    $platform = 'ios';
    $deviceToken = strtolower($rawToken);
}
if (!is_device_token($deviceToken, $platform)) {
    respond(400, ['ok' => false, 'error' => 'Invalid device token']);
}
// Live Activities are iOS-only; an Android device never has a meaningful startToken.
$startToken = ($platform === 'ios' && isset($body['startToken'])) ? strtolower((string) $body['startToken']) : null;
if ($startToken !== null && !is_apns_token($startToken)) {
    $startToken = null;
}
$prefs = is_array($body['prefs'] ?? null) ? $body['prefs'] : [];

$device = [
    'platform' => $platform,
    'lang' => ($body['lang'] ?? 'de') === 'hr' ? 'hr' : 'de',
    'prefs' => [
        'live' => !empty($prefs['live']),
        'goals' => !empty($prefs['goals']),
        'lineup' => !empty($prefs['lineup']),
    ],
    'updated' => gmdate('Y-m-d\TH:i:s\Z'),
];
if (array_key_exists('liveActivity', $prefs)) {
    $device['prefs']['liveActivity'] = !empty($prefs['liveActivity']);
}
if ($startToken !== null) {
    $device['startToken'] = $startToken;
}

$ok = nkcu_fan_update_store(function (&$store) use ($deviceToken, $device) {
    if (!isset($device['startToken']) && isset($store['devices'][$deviceToken]['startToken'])) {
        $device['startToken'] = $store['devices'][$deviceToken]['startToken'];
    }
    $store['devices'][$deviceToken] = $device;
    return true;
});

respond($ok ? 200 : 500, ['ok' => (bool) $ok]);
