<?php

require_once __DIR__ . '/../config/config.php';

$input = json_decode(file_get_contents('php://input'), true);
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

try {
    $rawText = scrapeArticle($url);

    // Optional: Text nachbearbeiten oder umformulieren, z.B. GPT-Anbindung (später)
    $cleanText = trim($rawText);

    echo json_encode(['success' => true, 'text' => $cleanText]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
