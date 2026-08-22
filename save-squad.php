<?php
/**
 * Writes a single match's saved lineup into data/live_squad.json.
 * Called by admin.html. Requires the admin password on every request
 * since the client-side gate in admin.html is cosmetic only.
 */

header('Content-Type: application/json; charset=utf-8');

define('ADMIN_PASSWORD', 'croatia1971');
define('DATA_FILE', __DIR__ . '/data/live_squad.json');

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (!isset($body['password']) || !hash_equals(ADMIN_PASSWORD, (string) $body['password'])) {
    respond(401, ['ok' => false, 'error' => 'Falsches Passwort']);
}

$matchId = isset($body['matchId']) ? trim((string) $body['matchId']) : '';
$date = isset($body['date']) ? (string) $body['date'] : '';
$home = isset($body['home']) ? (string) $body['home'] : '';
$away = isset($body['away']) ? (string) $body['away'] : '';
$formation = isset($body['formation']) ? (string) $body['formation'] : '';
$lineup = isset($body['lineup']) && is_array($body['lineup']) ? $body['lineup'] : null;

if ($matchId === '' || $date === '' || $home === '' || $away === '' || $lineup === null) {
    respond(400, ['ok' => false, 'error' => 'Missing required fields']);
}

// Only plain strings as slot => player-name values.
$cleanLineup = [];
foreach ($lineup as $slot => $name) {
    if (is_string($slot) && is_string($name) && $name !== '') {
        $cleanLineup[$slot] = $name;
    }
}

$entry = [
    'matchId' => $matchId,
    'date' => $date,
    'home' => $home,
    'away' => $away,
    'formation' => $formation,
    'lineup' => $cleanLineup,
    'updated' => gmdate('Y-m-d\TH:i:s\Z'),
];

if (!is_dir(dirname(DATA_FILE))) {
    respond(500, ['ok' => false, 'error' => 'data/ directory not found']);
}

// Open (creating if needed), lock, read-modify-write, so concurrent saves
// from different admins never clobber each other.
$fh = fopen(DATA_FILE, 'c+');
if ($fh === false) {
    respond(500, ['ok' => false, 'error' => 'Could not open data file']);
}

if (!flock($fh, LOCK_EX)) {
    fclose($fh);
    respond(500, ['ok' => false, 'error' => 'Could not lock data file']);
}

$size = filesize(DATA_FILE);
$contents = $size > 0 ? fread($fh, $size) : '';
$data = json_decode($contents, true);
if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
    $data = ['matches' => new stdClass()];
    $data['matches'] = [];
}

// Preserve any goals already logged for this match (saveSquad only owns the
// lineup/formation fields; save-goal.php owns goals/scores).
if (isset($data['matches'][$matchId]) && is_array($data['matches'][$matchId])) {
    $existing = $data['matches'][$matchId];
    if (isset($existing['goals'])) { $entry['goals'] = $existing['goals']; }
    if (isset($existing['homeScore'])) { $entry['homeScore'] = $existing['homeScore']; }
    if (isset($existing['awayScore'])) { $entry['awayScore'] = $existing['awayScore']; }
    if (isset($existing['live'])) { $entry['live'] = $existing['live']; }
}

$data['matches'][$matchId] = $entry;

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $json);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

respond(200, ['ok' => true, 'entry' => $entry]);
