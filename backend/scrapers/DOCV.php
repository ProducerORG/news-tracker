<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class DOCV {
    public function fetch() {
        $sourceName = 'DOCV';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite $baseUrl nicht erreichbar.\n";
            return [];
        }

        $results = [];

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Korrigierter XPath: <a class="card-link">
        $entries = $xpath->query('//div[contains(@class,"card-columns")]//a[contains(@class,"card-link")]');
        if ($entries->length === 0) {
            echo "Keine Einträge gefunden. Beende.\n";
            return [];
        }

        foreach ($entries as $entry) {
            $titleNode = $xpath->query('.//h5[contains(@class,"card-title")]', $entry)->item(0);
            $dateNode = $xpath->query('.//small[contains(@class,"text-muted")]', $entry)->item(0);

            if (!$titleNode || !$dateNode) {
                echo "Unvollständiger Eintrag, wird übersprungen.\n";
                continue;
            }

            $title = trim($titleNode->textContent);
            $href = $entry->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://casinoverband.de' . $href;
            $dateRaw = trim($dateNode->textContent);

            $dateTime = DateTime::createFromFormat('Y-m-d', $dateRaw);
            if (!$dateTime) {
                echo "Ungültiges Datum: $dateRaw – wird übersprungen.\n";
                continue;
            }

            $limitDate = new DateTime('-6 weeks');
            if ($dateTime < $limitDate) {
                echo "Übersprungen (zu alt): {$dateRaw}\n";
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
