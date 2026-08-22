<?php
/**
 * Removes one goal event from a match's entry in data/live_squad.json and
 * recomputes homeScore/awayScore from the remaining goals. Called by the
 * bin icon next to each scorer row in admin.html.
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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

if (!isset($body['password']) || !hash_equals(ADMIN_PASSWORD, (string) $body['password'])) {
    respond(401, ['ok' => false, 'error' => 'Falsches Passwort']);
}

$matchId = isset($body['matchId']) ? trim((string) $body['matchId']) : '';
$goalId = isset($body['goalId']) ? trim((string) $body['goalId']) : '';

if ($matchId === '' || $goalId === '') {
    respond(400, ['ok' => false, 'error' => 'Missing matchId or goalId']);
}

if (!is_file(DATA_FILE)) {
    respond(404, ['ok' => false, 'error' => 'No data file yet']);
}

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
if (!is_array($data) || !isset($data['matches'][$matchId]) || !is_array($data['matches'][$matchId])) {
    flock($fh, LOCK_UN);
    fclose($fh);
    respond(404, ['ok' => false, 'error' => 'Match not found']);
}

$entry = $data['matches'][$matchId];
$goals = isset($entry['goals']) && is_array($entry['goals']) ? $entry['goals'] : [];

$goals = array_values(array_filter($goals, function ($g) use ($goalId) {
    return !isset($g['id']) || $g['id'] !== $goalId;
}));

$homeScore = 0;
$awayScore = 0;
foreach ($goals as $g) {
    if ($g['side'] === 'home') { $homeScore++; } else { $awayScore++; }
}

$entry['goals'] = $goals;
$entry['homeScore'] = $homeScore;
$entry['awayScore'] = $awayScore;
$entry['updated'] = gmdate('Y-m-d\TH:i:s\Z');

$data['matches'][$matchId] = $entry;

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $json);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

respond(200, ['ok' => true, 'entry' => $entry]);
