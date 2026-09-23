<?php
/**
 * Creates or updates one item in data/news.json — the public site's
 * "News & Anlässe" feed (index.html reads this file directly, see
 * fetchNews()/renderNewsCards() near the news modal JS). There is no web
 * admin UI for this: news is authored from the nkcu_ios admin app's News
 * tab, or by hand-editing the JSON.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth-lib.php';

require_once __DIR__ . '/apns.php';

define('DATA_FILE', dirname(__DIR__) . '/data/news.json');

// Only these survive nkcu_sanitize_basic_html() — the admin app's editor
// only ever offers bold/italic/paragraph breaks, so anything else arriving
// here is either a stale client or an attempted injection; either way it
// gets flattened to plain text rather than rejected outright.
define('NKCU_NEWS_ALLOWED_TAGS', ['p', 'br', 'strong', 'b', 'em', 'i']);

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function nkcu_sanitize_basic_html($html) {
    $doc = new DOMDocument();
    $ok = @$doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="nkcu-root">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    if (!$ok) {
        return '';
    }
    $root = $doc->getElementById('nkcu-root');
    if ($root === null) {
        return '';
    }
    return nkcu_sanitize_children($doc, $root);
}

function nkcu_sanitize_children($doc, $node) {
    $out = '';
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $out .= htmlspecialchars($child->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($child->nodeName);
            $inner = nkcu_sanitize_children($doc, $child);
            if (in_array($tag, NKCU_NEWS_ALLOWED_TAGS, true)) {
                $out .= $tag === 'br' ? '<br>' : "<$tag>$inner</$tag>";
            } else {
                $out .= $inner;
            }
        }
    }
    return $out;
}

/** "Sep 2026" — matches the short German month labels already used in data/news.json. */
function nkcu_news_month_label($timestamp) {
    $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    return $months[(int) gmdate('n', $timestamp) - 1] . ' ' . gmdate('Y', $timestamp);
}

function nkcu_news_excerpt($text, $maxLen = 140) {
    $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    $plain = preg_replace('/\s+/u', ' ', $plain);
    if (mb_strlen($plain, 'UTF-8') > $maxLen) {
        $plain = mb_substr($plain, 0, $maxLen, 'UTF-8');
        $plain = preg_replace('/\s+\S*$/u', '', $plain) . '…';
    }
    return $plain;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

// Only a logged-in admin-app user (their own session token); there is
// no shared password any more. See nkcu_require_session in auth-lib.php.
$actingUsername = nkcu_require_session($body['token'] ?? null);

$id = isset($body['id']) ? trim((string) $body['id']) : '';
$deTag = isset($body['deTag']) ? trim((string) $body['deTag']) : '';
$hrTag = isset($body['hrTag']) ? trim((string) $body['hrTag']) : '';
$deTitle = isset($body['deTitle']) ? trim((string) $body['deTitle']) : '';
$hrTitle = isset($body['hrTitle']) ? trim((string) $body['hrTitle']) : '';
$deTextRaw = isset($body['deText']) ? (string) $body['deText'] : '';
$hrTextRaw = isset($body['hrText']) ? (string) $body['hrText'] : '';
$pinned = !empty($body['pinned']);

if ($deTag === '' || $hrTag === '' || $deTitle === '' || $hrTitle === '' || $deTextRaw === '' || $hrTextRaw === '') {
    respond(400, ['ok' => false, 'error' => 'Missing required fields']);
}

$deText = nkcu_sanitize_basic_html($deTextRaw);
$hrText = nkcu_sanitize_basic_html($hrTextRaw);

$now = time();
$isNew = ($id === '');
if ($isNew) {
    $id = uniqid('n_', true);
}

$item = [
    'id' => $id,
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
    'date' => nkcu_news_month_label($now),
    'pinned' => $pinned,
    'de' => [
        'tag' => $deTag,
        'title' => $deTitle,
        'excerpt' => nkcu_news_excerpt($deText),
        'text' => $deText,
    ],
    'hr' => [
        'tag' => $hrTag,
        'title' => $hrTitle,
        'excerpt' => nkcu_news_excerpt($hrText),
        'text' => $hrText,
    ],
];

if (!is_dir(dirname(DATA_FILE))) {
    respond(500, ['ok' => false, 'error' => 'data/ directory not found']);
}

$ok = nkcu_json_update(DATA_FILE, ['items' => []], function ($data) use (&$item, $id, $isNew, $pinned) {
    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
    }

    // Only one item can be pinned (it's rendered as the single featured
    // banner on the site) — pinning this one un-pins whatever else was.
    if ($pinned) {
        foreach ($data['items'] as $i => $existing) {
            if (!empty($existing['pinned']) && (!isset($existing['id']) || $existing['id'] !== $id)) {
                $data['items'][$i]['pinned'] = false;
            }
        }
    }

    if (!$isNew) {
        foreach ($data['items'] as $i => $existing) {
            if (isset($existing['id']) && $existing['id'] === $id) {
                // An edit keeps its original publish date/position — only a
                // brand-new item is dated "now".
                $item['createdAt'] = $existing['createdAt'] ?? $item['createdAt'];
                $item['date'] = $existing['date'] ?? $item['date'];
                $data['items'][$i] = $item;
                return $data;
            }
        }
    }
    array_unshift($data['items'], $item);
    return $data;
});

if (!$ok) {
    respond(500, ['ok' => false, 'error' => 'Could not save news item']);
}

nkcu_notify_admin_of_change(
    $actingUsername,
    $isNew ? 'News Published' : 'News Updated',
    $item['de']['title']
);

respond(200, ['ok' => true, 'item' => $item]);
