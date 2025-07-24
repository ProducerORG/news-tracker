<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class ISAGuide {
    public function fetch() {
        $sourceName = 'ISAGuide';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 1;
        $maxPages = 10;

        while ($page <= $maxPages) {
            $url = $baseUrl . '/page/' . $page . '/';
            echo "Lade Seite: $url\n";

            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $entries = $xpath->query('//article[@itemscope and @itemtype="http://schema.org/NewsArticle"]');

            if ($entries->length === 0) {
                echo "Keine Artikel auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//h3[contains(@class,"entry-title")]/a', $entry)->item(0);
                $dateNode = $xpath->query('.//meta[@itemprop="datePublished"]', $entry)->item(0);

                if (!$titleNode || !$dateNode) {
                    continue;
                }

                $title = trim($titleNode->textContent);
                $href = trim($titleNode->getAttribute('href'));
                $dateRaw = $dateNode->getAttribute('content');

                if (!$title || !$href || !$dateRaw) {
                    continue;
                }

                $dateTime = new DateTime(substr($dateRaw, 0, 10) . ' 00:00:00');
                $limitDate = new DateTime('-6 weeks');
                if ($dateTime < $limitDate) {
                    echo "Übersprungen (zu alt): {$dateTime->format('Y-m-d H:i:s')}\n";
                    continue;
                }

                if ($this->existsPost($title, $href)) {
                    echo "Übersprungen (bereits vorhanden): $title\n";
                    continue;
                }

                $this->insertPost($dateTime->format('Y-m-d H:i:s'), $source['id'], $title, $href);
                $results[] = [
                    'date' => $dateTime->format('Y-m-d H:i:s'),
                    'title' => $title,
                    'link' => $href
                ];
            }

            $page++;
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
