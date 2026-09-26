<?php
/**
 * Push notifications + Live Activity updates for the public fan app
 * (ch.croatia-uzwil.app). Reuses apns.php's .p8 key and JWT signing —
 * the key belongs to the Apple team, so it works for every app in it.
 *
 * Storage: data/fan_devices.json (denied in .htaccess), shaped as
 *   devices:  { <apnsToken>: { lang, prefs: {live, goals, lineup},
 *               startToken?, updated } }
 *   activities: { <matchId>: [ <liveActivityPushToken>, ... ] }
 *   sent:     { <matchId>: { lineup: true } }   — one-shot guards
 *
 * The admin endpoints call nkcu_fan_* helpers after their write. Sending
 * is deferred until after the admin's HTTP response has been flushed
 * (fastcgi_finish_request), so a slow push never delays the console.
 */

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/apns.php';

define('FAN_APP_BUNDLE_ID', 'ch.croatia-uzwil.app');
define('FAN_DEVICES_FILE', dirname(__DIR__) . '/data/fan_devices.json');
// Must match the Swift struct name in the app and widget extension.
define('FAN_ACTIVITY_ATTRIBUTES_TYPE', 'MatchActivityAttributes');

/** Read-modify-write fan_devices.json under an exclusive lock. */
function nkcu_fan_update_store(callable $mutate) {
    $fh = fopen(FAN_DEVICES_FILE, 'c+');
    if ($fh === false) { return null; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); return null; }
    $size = filesize(FAN_DEVICES_FILE);
    $store = json_decode($size > 0 ? fread($fh, $size) : '', true);
    if (!is_array($store)) { $store = []; }
    foreach (['devices', 'activities', 'sent'] as $k) {
        if (!isset($store[$k]) || !is_array($store[$k])) { $store[$k] = []; }
    }
    $result = $mutate($store);
    $out = $store;
    foreach (['devices', 'activities', 'sent'] as $k) {
        if (empty($out[$k])) { $out[$k] = new stdClass(); }
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function nkcu_fan_read_store() {
    $store = is_readable(FAN_DEVICES_FILE) ? json_decode((string) file_get_contents(FAN_DEVICES_FILE), true) : null;
    if (!is_array($store)) { $store = []; }
    foreach (['devices', 'activities', 'sent'] as $k) {
        if (!isset($store[$k]) || !is_array($store[$k])) { $store[$k] = []; }
    }
    return $store;
}

/**
 * Sends a batch of pushes over one reused HTTP/2 connection.
 * $jobs: list of [token, payloadArray, pushType ('alert'|'liveactivity')].
 * Returns the tokens APNs reported as dead (410 / BadDeviceToken /
 * Unregistered) so the caller can prune them.
 */
function nkcu_fan_send_batch(array $jobs) {
    if (!$jobs || !nkcu_apns_configured()) { return []; }
    $jwt = nkcu_apns_make_jwt();
    if ($jwt === null) { return []; }

    $host = APNS_USE_SANDBOX ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
    $dead = [];
    $ch = curl_init();
    foreach ($jobs as $job) {
        list($token, $payload, $pushType) = $job;
        $isActivity = $pushType === 'liveactivity';
        curl_setopt_array($ch, [
            CURLOPT_URL => "$host/3/device/$token",
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . FAN_APP_BUNDLE_ID . ($isActivity ? '.push-type.liveactivity' : ''),
                'apns-push-type: ' . $pushType,
                'apns-priority: 10',
                'content-type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status === 410 || ($status === 400 && is_string($response) && strpos($response, 'BadDeviceToken') !== false)) {
            $dead[] = $token;
        }
    }
    curl_close($ch);
    return $dead;
}

function nkcu_fan_prune(array $deadTokens) {
    if (!$deadTokens) { return; }
    nkcu_fan_update_store(function (&$store) use ($deadTokens) {
        foreach ($deadTokens as $t) {
            unset($store['devices'][$t]);
            foreach ($store['devices'] as $k => $d) {
                if (($d['startToken'] ?? null) === $t) { unset($store['devices'][$k]['startToken']); }
            }
            foreach ($store['activities'] as $mid => $tokens) {
                $store['activities'][$mid] = array_values(array_diff($tokens, [$t]));
            }
        }
    });
}

/** Runs $fn after the admin's response has been sent. */
function nkcu_fan_defer(callable $fn) {
    register_shutdown_function(function () use ($fn) {
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        ignore_user_abort(true);
        try { $fn(); } catch (Throwable $e) { /* never break the admin write */ }
    });
}

function nkcu_fan_team_name($name) {
    return preg_match('/\buzwil\b/i', (string) $name) ? 'NK Croatia Uzwil' : (string) $name;
}

/**
 * "5. Spiel": this match's number among our league games in
 * data/spiele.json (date order, friendlies flagged `dummy` skipped).
 * Same matchId formula as the site/app (lsMatchId / Fixture.matchId).
 */
function nkcu_fan_game_label($matchId, $lang) {
    $file = dirname(__DIR__) . '/data/spiele.json';
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    $games = [];
    foreach (($data['fixtures'] ?? []) as $f) {
        if (!empty($f['dummy'])) { continue; }
        if (!preg_match('/\buzwil\b/i', ($f['home'] ?? '') . ' ' . ($f['away'] ?? ''))) { continue; }
        $d = explode('.', (string) ($f['date'] ?? ''));
        if (count($d) !== 3) { continue; }
        $games[] = [
            'key' => sprintf('%04d-%02d-%02d', $d[2], $d[1], $d[0]),
            'id' => preg_replace('/[^a-zA-Z0-9]+/', '-', $f['date'] . '__' . $f['home'] . '__' . $f['away']),
        ];
    }
    usort($games, function ($a, $b) { return strcmp($a['key'], $b['key']); });
    foreach ($games as $i => $g) {
        if ($g['id'] === $matchId) {
            return ($i + 1) . ($lang === 'hr' ? '. UTAKMICA' : '. SPIEL');
        }
    }
    return '';
}

/**
 * Latest goal as one line. Our goals carry the score at that moment
 * ("58' · 2:1 S. Cucinelli"); opponent goals just minute + team.
 */
function nkcu_fan_last_event($entry) {
    $goals = isset($entry['goals']) && is_array($entry['goals']) ? $entry['goals'] : [];
    if (!$goals) { return ''; }
    usort($goals, function ($a, $b) { return ($a['minute'] ?? 0) <=> ($b['minute'] ?? 0); });
    $h = 0; $a = 0;
    foreach ($goals as $g) { if (($g['side'] ?? '') === 'home') { $h++; } else { $a++; } }
    $last = end($goals);
    $side = $last['side'] ?? '';
    $minute = (int) ($last['minute'] ?? 0);
    $usHome = (bool) preg_match('/\buzwil\b/i', (string) ($entry['home'] ?? ''));
    $isOurs = ($side === 'home') === $usHome;
    if ($isOurs) {
        return "$minute' · $h:$a " . (string) ($last['scorer'] ?? '');
    }
    return "$minute' " . nkcu_fan_team_name($side === 'home' ? ($entry['home'] ?? '') : ($entry['away'] ?? ''));
}

function nkcu_fan_content_state($entry, $lastEvent) {
    return [
        'homeScore' => (int) ($entry['homeScore'] ?? 0),
        'awayScore' => (int) ($entry['awayScore'] ?? 0),
        'lastEvent' => (string) $lastEvent,
    ];
}

/**
 * Notification body: both clubs on the first line, then extra lines.
 * iOS shows the title on a single line (long club names got cut off),
 * while the body wraps — so the clubs and details live here.
 */
function nkcu_fan_body($home, $away, array $extraLines = []) {
    return implode("\n", array_merge(["$home – $away"], array_filter($extraLines, 'strlen')));
}

function nkcu_fan_formation_line($entry, $lang) {
    $f = trim((string) ($entry['formation'] ?? ''));
    return $f === '' ? '' : (($lang === 'hr' ? 'Formacija ' : 'Formation ') . $f);
}

/** Alert pushes to every device that has $pref on, in its own language. */
function nkcu_fan_alert_jobs(array $store, $pref, callable $textFor, array $skipTokens = []) {
    $jobs = [];
    foreach ($store['devices'] as $token => $d) {
        if (in_array($token, $skipTokens, true)) { continue; }
        if (empty($d['prefs'][$pref])) { continue; }
        list($title, $body) = $textFor(($d['lang'] ?? 'de') === 'hr' ? 'hr' : 'de');
        $jobs[] = [$token, [
            'aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default'],
        ], 'alert'];
    }
    return $jobs;
}

/** Match flipped live (or back). Starts / ends Live Activities too. */
function nkcu_fan_on_live_changed($matchId, $entry, $live) {
    nkcu_fan_defer(function () use ($matchId, $entry, $live) {
        $store = nkcu_fan_read_store();
        $home = nkcu_fan_team_name($entry['home'] ?? '');
        $away = nkcu_fan_team_name($entry['away'] ?? '');
        $jobs = [];

        if ($live) {
            $started = [];
            foreach ($store['devices'] as $token => $d) {
                if (empty($d['prefs']['live']) || empty($d['startToken'])) { continue; }
                $hr = ($d['lang'] ?? 'de') === 'hr';
                // Push-to-start: the Live Activity's own alert is the
                // notification, so this device gets no separate alert.
                $jobs[] = [$d['startToken'], ['aps' => [
                    'timestamp' => time(),
                    'event' => 'start',
                    'attributes-type' => FAN_ACTIVITY_ATTRIBUTES_TYPE,
                    'attributes' => [
                        'matchId' => $matchId, 'home' => $home, 'away' => $away,
                        'gameLabel' => nkcu_fan_game_label($matchId, $hr ? 'hr' : 'de'),
                    ],
                    'content-state' => nkcu_fan_content_state($entry, nkcu_fan_last_event($entry)),
                    'alert' => [
                        'title' => $hr ? '🔴 Utakmica je uživo' : '🔴 Das Spiel ist live',
                        'body' => nkcu_fan_body($home, $away, [nkcu_fan_formation_line($entry, $hr ? 'hr' : 'de')]),
                    ],
                    'sound' => 'default',
                ]], 'liveactivity'];
                $started[] = $token;
            }
            $jobs = array_merge($jobs, nkcu_fan_alert_jobs($store, 'live', function ($l) use ($home, $away, $entry) {
                return [
                    $l === 'hr' ? '🔴 Utakmica je uživo' : '🔴 Das Spiel ist live',
                    nkcu_fan_body($home, $away, [nkcu_fan_formation_line($entry, $l)]),
                ];
            }, $started));
        } else {
            foreach ($store['activities'][$matchId] ?? [] as $t) {
                $jobs[] = [$t, ['aps' => [
                    'timestamp' => time(),
                    'event' => 'end',
                    'content-state' => nkcu_fan_content_state($entry, 'Schluss'),
                    'dismissal-date' => time() + 2 * 3600,
                ]], 'liveactivity'];
            }
        }
        nkcu_fan_prune(nkcu_fan_send_batch($jobs));
    });
}

/** A goal was added: alert (both teams) + Live Activity update. */
function nkcu_fan_on_goal($matchId, $entry, $goal) {
    nkcu_fan_defer(function () use ($matchId, $entry, $goal) {
        $store = nkcu_fan_read_store();
        $home = nkcu_fan_team_name($entry['home'] ?? '');
        $away = nkcu_fan_team_name($entry['away'] ?? '');
        $score = (int) ($entry['homeScore'] ?? 0) . ':' . (int) ($entry['awayScore'] ?? 0);
        $minute = (int) ($goal['minute'] ?? 0);
        $scorer = (string) ($goal['scorer'] ?? '');
        $team = ($goal['side'] ?? '') === 'home' ? $home : $away;
        $isOpponent = $scorer === '' || $scorer === 'Gegner';
        $event = nkcu_fan_last_event($entry);

        $jobs = nkcu_fan_alert_jobs($store, 'goals', function ($l) use ($home, $away, $score, $minute, $scorer, $team, $isOpponent) {
            $title = '⚽ ' . ($l === 'hr' ? 'GOL' : 'TOR') . " – $score";
            $who = $isOpponent ? $team : $scorer;
            return [$title, nkcu_fan_body($home, $away, ["$minute' $who"])];
        });
        foreach ($store['activities'][$matchId] ?? [] as $t) {
            $jobs[] = [$t, ['aps' => [
                'timestamp' => time(),
                'event' => 'update',
                'content-state' => nkcu_fan_content_state($entry, $event),
            ]], 'liveactivity'];
        }
        nkcu_fan_prune(nkcu_fan_send_batch($jobs));
    });
}

/** A goal was deleted (correction): silent Live Activity update only. */
function nkcu_fan_on_score_corrected($matchId, $entry) {
    nkcu_fan_defer(function () use ($matchId, $entry) {
        $store = nkcu_fan_read_store();
        $jobs = [];
        foreach ($store['activities'][$matchId] ?? [] as $t) {
            $jobs[] = [$t, ['aps' => [
                'timestamp' => time(),
                'event' => 'update',
                'content-state' => nkcu_fan_content_state($entry, nkcu_fan_last_event($entry)),
            ]], 'liveactivity'];
        }
        nkcu_fan_prune(nkcu_fan_send_batch($jobs));
    });
}

/**
 * Lineup saved. Notifies once per match, the first time a full eleven
 * is saved — the console saves on every edit, and fans shouldn't get a
 * ping per position change.
 */
function nkcu_fan_on_lineup_saved($matchId, $entry) {
    $lineup = isset($entry['lineup']) && is_array($entry['lineup']) ? $entry['lineup'] : [];
    if (count($lineup) < 11) { return; }
    $first = nkcu_fan_update_store(function (&$store) use ($matchId) {
        if (!empty($store['sent'][$matchId]['lineup'])) { return false; }
        $store['sent'][$matchId]['lineup'] = true;
        return true;
    });
    if (!$first) { return; }
    nkcu_fan_defer(function () use ($entry) {
        $store = nkcu_fan_read_store();
        $home = nkcu_fan_team_name($entry['home'] ?? '');
        $away = nkcu_fan_team_name($entry['away'] ?? '');
        $jobs = nkcu_fan_alert_jobs($store, 'lineup', function ($l) use ($home, $away, $entry) {
            $title = $l === 'hr' ? '📋 Postava je objavljena' : '📋 Aufstellung ist da';
            return [$title, nkcu_fan_body($home, $away, [nkcu_fan_formation_line($entry, $l)])];
        });
        nkcu_fan_prune(nkcu_fan_send_batch($jobs));
    });
}

/** Match reset in the console: end its activities, forget its guards. */
function nkcu_fan_on_match_reset($matchId) {
    $tokens = nkcu_fan_update_store(function (&$store) use ($matchId) {
        $t = $store['activities'][$matchId] ?? [];
        unset($store['activities'][$matchId], $store['sent'][$matchId]);
        return $t;
    }) ?: [];
    nkcu_fan_defer(function () use ($tokens) {
        $jobs = [];
        foreach ($tokens as $t) {
            $jobs[] = [$t, ['aps' => [
                'timestamp' => time(),
                'event' => 'end',
                'content-state' => ['homeScore' => 0, 'awayScore' => 0, 'lastEvent' => ''],
                'dismissal-date' => time(),
            ]], 'liveactivity'];
        }
        nkcu_fan_send_batch($jobs);
    });
}
