<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class Automatenmarkt {
    public function fetch() {
        $sourceName = 'Automatenmarkt';
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
            $url = $baseUrl . '?tx_tonic_record%5B%40widget_pager_0%5D%5BcurrentPage%5D=' . $page;
            echo "Lade Seite: $url\n";
            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $entries = $xpath->query('//div[contains(@class,"record-list-entry")]');

            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//div[contains(@class,"headline")]', $entry)->item(0);
                $linkNode = $xpath->query('.//a', $entry)->item(0);

                if ($titleNode && $linkNode) {
                    $title = trim($titleNode->textContent);
                    $href = $linkNode->getAttribute('href');
                    $link = (strpos($href, 'http') === 0) ? $href : $baseUrl . '/' . ltrim($href, '/');

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

        // Automarkenmarkt nutzt TYPO3: <meta name="date" content="YYYY-MM-DD">
        $metaDate = $xpath->query('//meta[@name="date"]')->item(0);
        if ($metaDate) {
            $dateRaw = $metaDate->getAttribute('content');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
                return $dateRaw . ' 00:00:00';
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
