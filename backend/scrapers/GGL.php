<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class GGL {
    public function fetch() {
        $sourceName = 'GGL';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite $baseUrl nicht erreichbar. Beende.\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $entries = $xpath->query('//div[contains(@class,"el-item")]');
        if ($entries->length === 0) {
            echo "Keine Einträge gefunden.\n";
            return [];
        }

        $results = [];
        foreach ($entries as $entry) {
            $titleNode = $xpath->query('.//div[contains(@class,"el-title")]', $entry)->item(0);
            $linkNode = $xpath->query('.//a[contains(@class,"el-link")]', $entry)->item(0);
            $dateNode = $xpath->query('.//div[contains(@class,"el-meta")]', $entry)->item(0);

            if (!$titleNode || !$linkNode || !$dateNode) {
                echo "Unvollständiger Eintrag – übersprungen.\n";
                continue;
            }

            $title = trim($titleNode->textContent);
            $href = $linkNode->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://www.gluecksspiel-behoerde.de' . $href;
            $dateText = trim($dateNode->textContent);

            $articleDate = $this->parseGermanDate($dateText);
            if (!$articleDate) {
                echo "Kein gültiges Datum für: $link – übersprungen.\n";
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

        return $results;
    }

    private function parseGermanDate($text) {
        if (preg_match('/(\d{2})\.\s?(\d{2})\.\s?(\d{4})/', $text, $matches)) {
            $date = DateTime::createFromFormat('d.m.Y', "$matches[1].$matches[2].$matches[3]");
            return $date ? $date->format('Y-m-d') . ' 00:00:00' : null;
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
