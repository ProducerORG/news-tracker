<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../frontend/public/api.php';
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

class GoogleNews {
    private $apiKey;

    public function __construct() {
        $this->apiKey = SERPAPI_KEY;  
    }

    public function fetch() {
        $sourceName = 'GoogleNews';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $keywordsRaw = "Glücksspiel|Sportwetten|Automatenspiel";
        $keywordList = explode('|', $keywordsRaw);

        $client = new Client([
            'timeout' => 30,
        ]);

        $results = [];
        $maxPerKeyword = 5;

        foreach ($keywordList as $keyword) {
            $keyword = trim($keyword);
            echo "Suche nach Keyword: {$keyword}\n";

            // API-Parameter für die SerpApi-Nachrichtensuche
            $params = [
                'q' => $keyword,
                'tbm' => 'nws', // Nur Nachrichten suchen
                'api_key' => $this->apiKey,
                'hl' => 'de', // Deutsch als Sprache
                'tbs' => 'qdr:d', // Artikel der letzten 14 Tage
            ];

            try {
                $response = $client->request('GET', 'https://serpapi.com/search', [
                    'query' => $params
                ]);
                $body = $response->getBody();
                $data = json_decode($body, true);

                if (isset($data['error'])) {
                    echo "API-Fehler: {$data['error']}\n";
                    continue;
                }

                $items = $data['news_results'] ?? [];
                $perKeywordCollected = 0;

                foreach ($items as $item) {
                    if ($perKeywordCollected >= $maxPerKeyword) break;

                    $title = trim($item['title'] ?? '');
                    $link = trim($item['link'] ?? '');
                    $dateRaw = $item['date'] ?? null;

                    if (!$title || !$link) continue;
                    if ($this->existsPost($title, $link)) {
                        echo "Übersprungen (bereits vorhanden): $title\n";
                        continue;
                    }

                    $dateTime = date('Y-m-d H:i:s', strtotime($dateRaw));
                    $this->insertPost($dateTime, $source['id'], $title, $link);

                    $results[] = [
                        'date' => $dateTime,
                        'title' => $title,
                        'link' => $link
                    ];

                    $perKeywordCollected++;
                }

                echo "Gespeichert für '{$keyword}': {$perKeywordCollected} Artikel\n";

            } catch (\Exception $e) {
                echo "Fehler bei Keyword '$keyword': " . $e->getMessage() . "\n";
                continue;
            }
        }

        return $results;
    }

    private function fetchSourceByName($name) {
        $sourcesJson = supabaseRequest('GET', 'sources?select=*&name=eq.' . urlencode($name));
        $sources = json_decode($sourcesJson, true);
        return $sources[0] ?? null;
    }

    private function existsPost($title, $link) {
        $queryTitle = 'posts?select=id&title=eq.' . urlencode($title);
        $queryLink = 'posts?select=id&link=eq.' . urlencode($link);

        $existsTitle = json_decode(supabaseRequest('GET', $queryTitle), true);
        if (!empty($existsTitle)) return true;

        $existsLink = json_decode(supabaseRequest('GET', $queryLink), true);
        if (!empty($existsLink)) return true;

        return false;
    }

    private function insertPost($date, $sourceId, $title, $link) {
        $data = [
            'date' => $date,
            'source_id' => $sourceId,
            'title' => $title,
            'link' => $link,
            'deleted' => false
        ];
        $response = supabaseRequest('POST', 'posts', $data);
        echo "Gespeichert: $title ($link)\n";
    }
}
