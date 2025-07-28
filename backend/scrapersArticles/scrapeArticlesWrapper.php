<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
file_put_contents('/tmp/input-log.json', $rawInput);
$input = json_decode($rawInput, true);

$url = $input['url'] ?? null;
$source = $input['source'] ?? null;
$manualText = $input['manualText'] ?? null;


if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'URL erforderlich']);
    exit;
}

// Quelle aus Domain ermitteln
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

if (!$source && !$manualText) {
    http_response_code(400);
    echo json_encode(['error' => 'Quelle nicht ermittelbar']);
    exit;
}

if (!$manualText) {
    $scraperPath = __DIR__ . "/$source.php";

    if (!file_exists($scraperPath)) {
        http_response_code(404);
        echo json_encode(['error' => "Kein Scraper für '$source'"]);
        exit;
    }

    require_once $scraperPath;

    if (!function_exists('scrapeArticle')) {
        http_response_code(500);
        echo json_encode(['error' => 'scrapeArticle()-Funktion fehlt']);
        exit;
    }
}

try {
    $manualText = $input['manualText'] ?? null;

    if ($manualText && strlen(trim($manualText)) >= 100) {
        $articleText = trim($manualText);
    } else {
        $rawText = scrapeArticle($url);
        if (!$rawText || strlen(trim($rawText)) < 100) {
            throw new Exception("Artikeltext zu kurz oder leer.");
        }
        $articleText = trim($rawText);
    }

    // GPT-Umschreibung
    $rewritten = rewriteWithGPT($articleText);

    // In Supabase speichern
    storeInSupabase($url, $articleText);

    ob_clean();
    echo json_encode(['text' => $rewritten]);
} catch (Exception $e) {
    $message = $e->getMessage();
    error_log("Fehler in Wrapper: " . $message);
    if ($message === 'Artikeltext zu kurz oder leer.') {
        http_response_code(200);
        echo json_encode([
            'manualRequired' => true,
            'url' => $url,
            'reason' => 'Artikeltext zu kurz oder leer'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $message]);
    }
    exit;
}

// GPT-Funktion
function rewriteWithGPT(string $text): string {
    $payload = [
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => 'Formuliere den folgenden Artikeltext journalistisch um. Keine Wiederholungen, keine Quellen. Verwende eine kurzes, ansprechendes, passendes Intro. Falls der Artikel fremdsprachig ist, übersetze ihn ins Deutsche'],
            ['role' => 'user', 'content' => $text]
        ],
        'temperature' => 0.7
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . GPT_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode >= 400) {
        throw new Exception("GPT API-Fehler: HTTP $httpCode");
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception("Ungültige GPT-Antwort");
    }

    return trim($result['choices'][0]['message']['content']);
}

// DB-Speicherung in Supabase
function storeInSupabase(string $url, string $articleText): void {
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    // Post zu dieser URL finden
    $lookupUrl = SUPABASE_URL . '/rest/v1/posts?link=eq.' . urlencode($url);
    $ch1 = curl_init($lookupUrl);
    curl_setopt_array($ch1, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $res1 = curl_exec($ch1);
    curl_close($ch1);

    $posts = json_decode($res1, true);
    if (!is_array($posts) || count($posts) === 0) {
        throw new Exception("Kein Post mit URL gefunden: $url");
    }

    $postId = $posts[0]['id'] ?? null;
    if (!$postId) {
        throw new Exception("Post-ID fehlt");
    }

    $updateUrl = SUPABASE_URL . '/rest/v1/posts?id=eq.' . $postId;
    $body = ['articletext' => $articleText];

    $ch2 = curl_init($updateUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($body)
    ]);
    curl_exec($ch2);
    curl_close($ch2);
}
