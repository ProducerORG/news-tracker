<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class UniHohenheim {
    public function fetch() {
        $sourceName = 'UniHohenheim';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite $baseUrl nicht erreichbar.\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $pressReleases = $xpath->query('//div[@id="jfmulticontent_c563001"]//table//tr');

        foreach ($pressReleases as $row) {
            $linkNode = $xpath->query('.//a', $row)->item(0);
            if (!$linkNode) continue;

            $title = trim($linkNode->textContent);
            $href = $linkNode->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://www.uni-hohenheim.de' . $href;

            // Datum aus dem restlichen Textinhalt extrahieren
            $rowText = trim($row->textContent);
            if (preg_match('/\((\d{2}\.\d{2}\.\d{4})\)/', $rowText, $matches)) {
                $germanDate = DateTime::createFromFormat('d.m.Y', $matches[1]);
                if (!$germanDate) {
                    echo "Ungültiges Datum bei: $title\n";
                    continue;
                }
                $dateTime = $germanDate;
            } else {
                echo "Kein Datum gefunden für: $title\n";
                continue;
            }

            // Nur Artikel der letzten 6 Wochen
            $limitDate = new DateTime('-6 weeks');
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
