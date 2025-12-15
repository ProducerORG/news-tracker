<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../frontend/public/api.php';
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

class GoogleNews {
    private $apiKey;
    private $excludedDomains = [
        'https://www.meinbezirk.at/',
        'https://www.tipico.de/wett-tipps/',
        'https://www.mopo.de/',
        'https://mopo.de/',
        "https://ekz-crosstour.ch"
    ];

    public function __construct() {
        $this->apiKey = SERPAPI_KEY;  // API-Schlüssel aus der Konstante laden
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
        shuffle($keywordList);
        $keywordList = array_slice($keywordList, 0, 2);

        $client = new Client([
            'timeout' => 30,
        ]);

        $results = [];
        $maxPerKeyword = 3;

        foreach ($keywordList as $keyword) {
            $keyword = trim($keyword);
            echo "Suche nach Keyword: {$keyword}\n";

            // API-Parameter für die SerpApi-Nachrichtensuche
            $params = [
                'q' => $keyword,
                'tbm' => 'nws', // Nur Nachrichten suchen
                'api_key' => $this->apiKey,  // API-Schlüssel wird hier übergeben
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

                // Sortieren nach Relevanz, falls SerpApi das nicht standardmäßig tut
                usort($items, function($a, $b) {
                    $scoreA = $a['serpapi_link_score'] ?? 0;
                    $scoreB = $b['serpapi_link_score'] ?? 0;
                    return $scoreB <=> $scoreA;
                });

                foreach ($items as $item) {
                    if ($perKeywordCollected >= $maxPerKeyword) break;

                    $title = trim($item['title'] ?? '');
                    $link = trim($item['link'] ?? '');
                    $dateRaw = $item['date'] ?? null;

                    if (!$title || !$link) continue;

                    // Exklusionsfilter für unerwünschte Domains
                    $skip = false;
                    foreach ($this->excludedDomains as $domain) {
                        if (strpos($link, $domain) === 0) {
                            $skip = true;
                            echo "Übersprungen (exkludierte Domain): $title ($link)\n";
                            break;
                        }
                    }
                    if ($skip) continue;

                    if ($this->isBlacklistedTitle($title)) {
                        echo "Übersprungen (Blacklist): $title\n";
                        continue;
                    }

                    if (empty($dateRaw) || $dateRaw === '1970-01-01 00:00:00') {
                        $dateTime = date('Y-m-d H:i:s');
                    } else {
                        try {
                            $dateTime = $this->parseDate($dateRaw);
                        } catch (\Exception $e) {
                            $dateTime = date('Y-m-d H:i:s');
                        }
                    }

                    if ($this->existsPost($title, $link)) {
                        echo "Übersprungen (bereits vorhanden): $title\n";
                        continue;
                    }

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

    private function isBlacklistedTitle($title) {
        $blacklist = [
            'gorans sportwetten',
            'das sind die gewinnzahlen vom',
            'tipp it like',
            'tipp & quoten',
            'wett-tipp mit prognose & quoten',
            'lottozahlen 6aus49',
            'tipp, prognose & quoten am'
        ];

        $titleLower = mb_strtolower($title);
        foreach ($blacklist as $term) {
            if (mb_strpos($titleLower, $term) !== false) {
                return true;
            }
        }

        return false;
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

    // Diese Methode konvertiert relative Zeitangaben wie 'vor 7 Stunden' oder 'vor 4 Tagen'
    private function parseDate($dateRaw) {
        $currentDate = new DateTime(); // Das aktuelle Datum

        // Wir überprüfen, ob die Zeitangabe wie "vor X Stunden" oder "vor X Tagen" aussieht
        if (preg_match('/vor (\d+) Stunden?/', $dateRaw, $matches)) {
            $hoursAgo = (int)$matches[1];
            $currentDate->modify("-{$hoursAgo} hours");
        } elseif (preg_match('/vor (\d+) Tagen?/', $dateRaw, $matches)) {
            $daysAgo = (int)$matches[1];
            $currentDate->modify("-{$daysAgo} days");
        } else {
            // Falls eine andere Zeitangabe kommt, versuche sie zu parsen
            try {
                $currentDate = new DateTime($dateRaw); // z.B. '2025-08-01 12:30:00'
            } catch (\Exception $e) {
                // Falls das Datum nicht korrekt ist, benutze das aktuelle Datum
                $currentDate = new DateTime();
            }
        }

        // Gib das Datum im Format Y-m-d H:i:s zurück
        return $currentDate->format('Y-m-d H:i:s');
    }
}
