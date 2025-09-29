<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class PresseportalBlaulicht {
    public function fetch() {
        $sourceName = 'PresseportalBlaulicht';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 0;
        $maxPages = 10;
        $limitDate = new DateTime('-6 weeks');

        while ($page < $maxPages) {
            $url = $baseUrl . '/' . ($page * 30); // Pagination in 30er-Schritten
            echo "Lade Seite: $url\n";

            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $entries = $xpath->query('//article[contains(@class,"news")]');

            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//h3/a', $entry)->item(0);
                $link = $titleNode ? $titleNode->getAttribute('href') : null;
                $title = $titleNode ? trim($titleNode->textContent) : null;

                $dateNode = $xpath->query('.//div[contains(@class,"news-meta")]/div[1]', $entry)->item(0);
                $dateText = $dateNode ? trim($dateNode->textContent) : null;
                $dateTime = $this->parseGermanDate($dateText);

                if (!$title || !$link || !$dateTime) {
                    echo "Fehlende Daten, übersprungen.\n";
                    continue;
                }

                if ($dateTime < $limitDate) {
                    echo "Übersprungen (zu alt): {$dateTime->format('Y-m-d')}\n";
                    continue;
                }

                if ($this->existsPost($title, $link)) {
                    echo "Übersprungen (bereits vorhanden): $title\n";
                    continue;
                }

                $this->insertPost($dateTime->format('Y-m-d H:i:s'), $source['id'], $title, $link);
                $results[] = [
                    'date' => $dateTime->format('Y-m-d H:i:s'),
                    'title' => $title,
                    'link' => $link
                ];
            }

            $page++;
        }

        return $results;
    }

    private function parseGermanDate($text) {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $m)) {
            return DateTime::createFromFormat('d.m.Y', $m[0]);
        }
        return null;
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
        if (!empty($existsTitle)) {
            return true;
        }

        $existsLink = json_decode(supabaseRequest('GET', $queryLink), true);
        if (!empty($existsLink)) {
            return true;
        }

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
