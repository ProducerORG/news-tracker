<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class GamesAndBusiness {
    public function fetch() {
        $sourceName = 'GamesAndBusiness';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite nicht erreichbar: $baseUrl\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $entries = $xpath->query('//div[contains(@class,"card_item")]');

        if ($entries->length === 0) {
            echo "Keine Artikel gefunden.\n";
            return [];
        }

        $results = [];

        foreach ($entries as $entry) {
            $linkNode = $xpath->query('.//a[contains(@class,"card_item_link")]', $entry)->item(0);
            $titleNode = $xpath->query('.//h4[not(@class)]', $entry)->item(0);  // Zweites <h4> ist der Titel

            if (!$linkNode || !$titleNode) {
                continue;
            }

            $href = trim($linkNode->getAttribute('href'));
            if (!$href || stripos($href, '/abo') !== false || stripos($href, '/newsletter') !== false || stripos($href, '/dossier') !== false) {
                echo "Übersprungen (statische Seite): $href\n";
                continue;
            }
            if (strpos($href, 'http') !== 0) {
                $href = $baseUrl . '/' . ltrim($href, '/');
            }


            $title = trim($titleNode->textContent);
            if (empty($title) || empty($href)) {
                continue;
            }

            $articleDate = $this->fetchArticleDate($href);
            if (!$articleDate) {
                echo "Kein Datum gefunden für: $href\n";
                continue;
            }

            $dateTime = new DateTime($articleDate);
            $limitDate = new DateTime('-6 weeks');
            if ($dateTime < $limitDate) {
                echo "Übersprungen (zu alt): {$articleDate}\n";
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

        return $results;
    }

    private function fetchArticleDate($url) {
        $html = @file_get_contents($url);
        if (!$html) {
            echo "Artikel nicht erreichbar: $url\n";
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Versuch: OpenGraph modified_time
        $metaNode = $xpath->query('//meta[@property="article:modified_time"]')->item(0);
        if ($metaNode) {
            $content = $metaNode->getAttribute('content');
            if ($content && preg_match('/\d{4}-\d{2}-\d{2}T/', $content)) {
                return substr($content, 0, 10) . ' 00:00:00';
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
