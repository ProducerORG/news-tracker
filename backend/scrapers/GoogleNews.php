<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../frontend/public/api.php';
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

class GoogleNews {
    public function fetch() {
        $sourceName = 'GoogleNews';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $login = defined('DATAFORSEO_LOGIN') ? DATAFORSEO_LOGIN : null;
        $password = defined('DATAFORSEO_PASSWORD') ? DATAFORSEO_PASSWORD : null;
        $keywordsRaw = "Glücksspiel|Sportwetten|Automatenspiel";

        if (!$login || !$password || !$keywordsRaw) {
            echo "Fehlende Umgebungsvariablen. Abbruch.\n";
            return [];
        }

        $keywordList = explode('|', $keywordsRaw);
        $client = new Client([
            'auth' => [$login, $password],
            'base_uri' => 'https://api.dataforseo.com/',
            'timeout' => 30,
        ]);

        $results = [];
        $maxPerKeyword = 5;

        foreach ($keywordList as $keyword) {
            $keyword = trim($keyword);
            echo "Suche nach Keyword: {$keyword}\n";

            $postData = [[
                "language_code" => "de",
                "location_code" => 2276,
                "keyword" => $keyword,
                "calculate_rectangles" => true
            ]];

            try {
                $response = $client->post('/v3/serp/google/news/live/advanced', [
                    'json' => $postData
                ]);
                $body = json_decode($response->getBody(), true);

                if ($body['status_code'] !== 20000) {
                    echo "API-Fehler: {$body['status_message']} ({$body['status_code']})\n";
                    continue;
                }

                $items = $body['tasks'][0]['result'][0]['items'] ?? [];
                $perKeywordCollected = 0;

                foreach ($items as $item) {
                    if ($perKeywordCollected >= $maxPerKeyword) break;

                    $title = trim($item['title'] ?? '');
                    $link = trim($item['url'] ?? '');
                    $dateRaw = $item['datetime'] ?? null;

                    if (!$title || !$link) continue;
                    if ($this->existsPost($title, $link)) {
                        echo "Übersprungen (bereits vorhanden): $title\n";
                        continue;
                    }

                    $dateTime = $dateRaw ? date('Y-m-d H:i:s', strtotime($dateRaw)) : date('Y-m-d H:i:s');
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
