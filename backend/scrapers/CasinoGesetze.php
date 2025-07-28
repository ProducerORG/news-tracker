<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class CasinoGesetze {
    public function fetch() {
        $sourceName = 'CasinoGesetze';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 1;
        $maxPages = 10;

        while ($page <= $maxPages) {
            $url = $page === 1 ? $baseUrl : $baseUrl . '/page:' . $page;
            echo "Lade Seite: $url\n";
            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $entries = $xpath->query('//li[contains(@class,"news-location-home")]');
            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//strong[@class="titleclass"]', $entry)->item(0);
                $linkNode = $xpath->query('.//a[contains(@class,"bestellen")]', $entry)->item(0);

                if ($titleNode && $linkNode) {
                    $title = trim($titleNode->textContent);
                    $href = $linkNode->getAttribute('href');
                    $link = (strpos($href, 'http') === 0) ? $href : 'https://casino-gesetze.de' . $href;

                    $articleDate = $this->fetchArticleDate($link);
                    if (!$articleDate) {
                        echo "Kein Datum gefunden für: $link, wird übersprungen.\n";
                        continue;
                    }

                    $dateTime = new DateTime($articleDate);
                    $limitDate = new DateTime('-6 weeks');
                    if ($dateTime < $limitDate) {
                        echo "Übersprungen (zu alt): {$articleDate}\n";
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
            }

            $page++;
        }

        return $results;
    }

    private function fetchArticleDate($link) {
        $html = @file_get_contents($link);
        if (!$html) {
            echo "Artikel nicht erreichbar: $link\n";
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Versuche Datum aus dem Titel oder dem HTML-Content zu extrahieren
        $textNodes = $xpath->query('//main//text()');
        foreach ($textNodes as $node) {
            if (preg_match('/(\d{2}\.\d{2}\.\d{4})/', $node->textContent, $matches)) {
                $date = DateTime::createFromFormat('d.m.Y', $matches[1]);
                if ($date) {
                    return $date->format('Y-m-d') . ' 00:00:00';
                }
            }
        }

        // Fallback: versuche aus der URL zu extrahieren (z. B. /wsop-2025-so-viel-steuern-zahlen-pokerprofis)
        if (preg_match('/(\d{6,})/', $html, $matches)) {
            $timestamp = (int)substr($matches[1], -10);
            if ($timestamp > 1000000000) {
                return date('Y-m-d H:i:s', $timestamp);
            }
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
