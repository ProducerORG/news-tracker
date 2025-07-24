<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

$url = $input['url'] ?? null;
$source = $input['source'] ?? null;

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'URL erforderlich']);
    exit;
}

if (!$source) {
    $host = parse_url($url, PHP_URL_HOST);
    $domainMap = [
        'isaguide.de' => 'ISAGuide',
        'example.org' => 'ExampleSource',
        // weitere Zuordnungen
    ];
    foreach ($domainMap as $domain => $src) {
        if (str_contains($host, $domain)) {
            $source = $src;
            break;
        }
    }
}

if (!$source) {
    http_response_code(400);
    echo json_encode(['error' => 'Source konnte nicht ermittelt werden']);
    exit;
}

$scraperPath = __DIR__ . "/$source.php";

if (!file_exists($scraperPath)) {
    http_response_code(404);
    echo json_encode(['error' => "Kein Scraper für Source '$source'"]);
    exit;
}

require_once $scraperPath;

if (!function_exists('scrapeArticle')) {
    http_response_code(500);
    echo json_encode(['error' => 'scrapeArticle()-Funktion fehlt im Scraper']);
    exit;
}

function supabaseRequest($method, $endpoint, $body = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function rewriteTextWithGPT($text) {
    $apiKey = getenv('GPT_KEY');
    if (!$apiKey) {
        throw new Exception("GPT_KEY nicht gesetzt");
    }

    $payload = [
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => 'Formuliere den folgenden deutschen Pressetext stilistisch um, ohne Fakten zu verändern.'],
            ['role' => 'user', 'content' => $text]
        ],
        'temperature' => 0.7
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new Exception("GPT API Fehler ($status): $response");
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

try {
    $rawText = scrapeArticle($url);
    $cleanText = trim($rawText);

    $postsJson = supabaseRequest('GET', 'posts?select=id&link=eq.' . urlencode($url));
    $posts = json_decode($postsJson, true);
    $postId = $posts[0]['id'] ?? null;

    if ($postId) {
        supabaseRequest('PATCH', 'posts?id=eq.' . $postId, ['articletext' => $cleanText]);
    }

    $rewritten = rewriteTextWithGPT($cleanText);

    ob_clean();
    echo json_encode(['text' => trim($rewritten)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'debug' => 'Exception in wrapper']);
}
