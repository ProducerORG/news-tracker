<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json');

file_put_contents('/tmp/wrapper-log.txt', "Wrapper gestartet\n", FILE_APPEND);

require_once __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
file_put_contents('/tmp/input-log.json', $rawInput);
$input = json_decode($rawInput, true);

$url = $input['url'] ?? null;
$source = $input['source'] ?? null;

if (!$url) {
    file_put_contents('/tmp/wrapper-log.txt', "Fehler: URL fehlt\n", FILE_APPEND);
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
    file_put_contents('/tmp/wrapper-log.txt', "Fehler: Source nicht ermittelbar\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Source konnte nicht ermittelt werden']);
    exit;
}

$scraperPath = __DIR__ . "/$source.php";

if (!file_exists($scraperPath)) {
    file_put_contents('/tmp/wrapper-log.txt', "Fehler: Scraper $scraperPath fehlt\n", FILE_APPEND);
    http_response_code(404);
    echo json_encode(['error' => "Kein Scraper für Source '$source'"]);
    exit;
}

require_once $scraperPath;

if (!function_exists('scrapeArticle')) {
    file_put_contents('/tmp/wrapper-log.txt', "Fehler: Funktion scrapeArticle() fehlt in $scraperPath\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'scrapeArticle()-Funktion fehlt im Scraper']);
    exit;
}

try {
    file_put_contents('/tmp/wrapper-log.txt', "Scraper $source wird aufgerufen\n", FILE_APPEND);
    $rawText = scrapeArticle($url);

    $cleanText = trim($rawText);
    ob_clean();
    echo json_encode(['text' => $cleanText]);
    file_put_contents('/tmp/wrapper-log.txt', "Scraper erfolgreich\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('/tmp/wrapper-log.txt', "Fehler bei scrapeArticle(): " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
