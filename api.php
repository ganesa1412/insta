<?php
// ================= CONFIG =================
const APIFY_TOKEN   = 'YOUR_APIFY_TOKEN_HERE';   // <-- edit your token here
const APIFY_ACTOR   = 'apify~instagram-scraper'; // actor used for posts / profiles
const STORY_ACTOR   = 'igview-owner~instagram-story-viewer'; // story actor, input: {"usernames":[...]}; '' disables stories
const RESULTS_LIMIT = 12;                        // max posts when a profile link is pasted
// ==========================================

set_time_limit(300);
$action = $_GET['action'] ?? 'fetch';

if ($action === 'download') { proxyMedia(); exit; }

header('Content-Type: application/json');

$url = trim($_POST['url'] ?? '');
if (!preg_match('~^https?://(www\.)?instagram\.com/~i', $url)) {
    out(['error' => 'Please paste a valid instagram.com link.']);
}
if (APIFY_TOKEN === 'YOUR_APIFY_TOKEN_HERE' || APIFY_TOKEN === '') {
    out(['error' => 'Set your Apify token in api.php first.']);
}

// Stories: a /stories/ link returns only stories; a profile link returns stories first, then posts
$stories = [];
$onlyStories = false;
$username = '';
if (preg_match('~instagram\.com/stories/([^/?]+)~i', $url, $m)) {
    $username = $m[1]; $onlyStories = true;
} elseif (preg_match('~^https?://(?:www\.)?instagram\.com/([A-Za-z0-9._]+)/?(?:\?.*)?$~i', $url, $m)
          && !in_array(strtolower($m[1]), ['p', 'reel', 'reels', 'tv', 'explore', 'stories'])) {
    $username = $m[1];
}
if ($username !== '' && STORY_ACTOR !== '') {
    $items = apify(STORY_ACTOR, ['usernames' => [$username]], $onlyStories);
    foreach ($items as $it) {
        $vid = $it['videoUrl'] ?? null;
        $img = $it['imageUrl'] ?? null;
        if (!$vid && !$img) continue;
        $stories[] = ['id' => 'story_' . ($it['storyId'] ?? uniqid()), 'owner' => $it['username'] ?? $username,
            'caption' => 'Story' . (!empty($it['takenAtFormatted']) ? ' - ' . $it['takenAtFormatted'] : ''),
            'media' => [['type' => $vid ? 'video' : 'image', 'thumb' => $img ?: $vid, 'url' => $vid ?: $img]]];
    }
}
if ($onlyStories) {
    if (STORY_ACTOR === '') out(['error' => 'Story links need a story actor. Set STORY_ACTOR in api.php.']);
    if (!$stories) out(['error' => 'No active stories found (private account, or none in the last 24h).']);
    out(['posts' => $stories]);
}

// Posts / reels / profiles
$input = [
    'directUrls'   => [$url],
    'resultsType'  => 'posts',
    'resultsLimit' => RESULTS_LIMIT,
    'addParentData' => false,
];
$items = apify(APIFY_ACTOR, $input);

$posts = $stories;
foreach ($items as $it) {
    if (!empty($it['error'])) continue;
    $media = [];
    $children = !empty($it['childPosts']) ? $it['childPosts'] : [$it];
    foreach ($children as $c) {
        $vid = $c['videoUrl'] ?? null;
        $img = $c['displayUrl'] ?? ($c['images'][0] ?? null);
        if (!$vid && !$img) continue;
        $media[] = ['type' => $vid ? 'video' : 'image', 'thumb' => $img ?: $vid, 'url' => $vid ?: $img];
    }
    if (!$media) continue;
    $posts[] = [
        'id'      => $it['shortCode'] ?? $it['id'] ?? uniqid(),
        'owner'   => $it['ownerUsername'] ?? '',
        'caption' => mb_substr($it['caption'] ?? '', 0, 200),
        'likes'   => $it['likesCount'] ?? null,
        'link'    => $it['url'] ?? '',
        'media'   => $media,
    ];
}
if (!$posts) out(['error' => 'No downloadable media found (private account, or bad link).']);
out(['posts' => $posts]);

// ---------- helpers ----------
function out($d) { echo json_encode($d); exit; }

// $strict = false: on failure return [] instead of aborting (so a story failure doesn't hide posts)
function apify($actor, $input, $strict = true) {
    if (!$strict) { try { return apifyCall($actor, $input); } catch (Exception $e) { return []; } }
    return apifyCall($actor, $input);
}

function apifyCall($actor, $input) {
    $ep = 'https://api.apify.com/v2/acts/' . $actor . '/run-sync-get-dataset-items?token=' . urlencode(APIFY_TOKEN);
    $ch = curl_init($ep);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($input),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 280,
        CURLOPT_SSL_VERIFYPEER => false, // XAMPP often lacks a CA bundle
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) out(['error' => 'Request failed: ' . $err]);
    $data = json_decode($res, true);
    if ($code >= 400 || !is_array($data)) {
        out(['error' => 'Apify error (' . $code . '): ' . ($data['error']['message'] ?? substr($res, 0, 200))]);
    }
    return $data;
}

// Streams the media through this server so the browser downloads it (avoids CORS / hotlink blocks)
function proxyMedia() {
    $u = $_GET['u'] ?? '';
    $host = parse_url($u, PHP_URL_HOST) ?: '';
    if (!preg_match('~(cdninstagram\.com|fbcdn\.net|instagram\.com)$~i', $host)) {
        http_response_code(400); exit('Blocked host');
    }
    $name = preg_replace('~[^a-z0-9._-]~i', '_', $_GET['name'] ?? 'instagram_media');
    $inline = isset($_GET['inline']);
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use ($name, $inline) {
            if (stripos($h, 'Content-Type:') === 0) header(trim($h));
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $d) use ($name, $inline) {
            static $sent = false;
            if (!$sent) {
                header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
                $sent = true;
            }
            echo $d;
            return strlen($d);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
}
