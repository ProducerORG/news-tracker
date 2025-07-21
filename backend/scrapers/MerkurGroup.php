<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class MerkurGroup {
    public function fetch() {
        $sourceName = 'MerkurGroup';
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

        $entries = $xpath->query('//div[@id="ausgabe"]/div');

        $results = [];

        foreach ($entries as $entry) {
            $dateNode = $xpath->query('.//span[contains(@class,"datum")]', $entry)->item(0);
            $titleNode = $xpath->query('.//h4/a', $entry)->item(0);
            $linkNode = $xpath->query('.//h4/a', $entry)->item(0);

            if ($dateNode && $titleNode && $linkNode) {
                $dateRaw = trim($dateNode->textContent);
                $dateParts = explode('.', $dateRaw);
                $dateFormatted = (count($dateParts) === 3)
                    ? sprintf('%04d-%02d-%02d 00:00:00', $dateParts[2], $dateParts[1], $dateParts[0])
                    : date('Y-m-d H:i:s');

                $title = trim($titleNode->textContent);
                $href = $linkNode->getAttribute('href');
                $link = (strpos($href, 'http') === 0) ? $href : rtrim($url, '/') . '/' . ltrim($href, '/');

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
