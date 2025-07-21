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

        $url = $source['url'];
        echo "Verwende URL aus DB: {$url}\n";
        $html = file_get_contents($url);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $entries = $xpath->query('//div[contains(@class,"carousel-item")]');

        $results = [];

        foreach ($entries as $entry) {
            $titleNode = $xpath->query('.//div[contains(@class,"headline")]', $entry)->item(0);
            $linkNode = $xpath->query('.//a[contains(@href,"/nachrichten/artikel")]', $entry)->item(0);

            if ($titleNode && $linkNode) {
                $title = trim($titleNode->textContent);
                $href = $linkNode->getAttribute('href');
                $link = (strpos($href, 'http') === 0) ? $href : rtrim($url, '/') . '/' . ltrim($href, '/');

                // Automatenmarkt hat i.d.R. keine Datumsauszeichnung → Jetzt-Datum setzen
                $dateFormatted = date('Y-m-d H:i:s');

                // 6 Wochen Filter
                $dateTime = new DateTime($dateFormatted);
                $limitDate = new DateTime('-6 weeks');
                if ($dateTime < $limitDate) {
                    echo "Übersprungen (zu alt): {$dateFormatted}\n";
                    continue;
                }

                if ($this->existsPost($title, $link)) {
                    echo "Übersprungen (bereits vorhanden): $title\n";
                    continue;
                }

                $this->insertPost($dateFormatted, $source['id'], $title, $link);
                $results[] = [
                    'date' => $dateFormatted,
                    'title' => $title,
                    'link' => $link
                ];
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
