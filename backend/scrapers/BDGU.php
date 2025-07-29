<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class BDGU {
    public function fetch() {
        $sourceName = 'BDGU';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = 'https://bdgu.de/aktuelles/';
        echo "Verwende URL: {$baseUrl}\n";

        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite $baseUrl nicht erreichbar.\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $results = [];

        $entries = $xpath->query('//div[contains(@class,"wf-cell") and contains(@class,"category-")]');

        if ($entries->length === 0) {
            echo "Keine Artikel gefunden.\n";
            return [];
        }

        foreach ($entries as $entry) {
            $classAttr = $entry->getAttribute('class');
            if (preg_match('/category-(\d+)/', $classAttr, $match)) {
                $categoryId = $match[1];
            } else {
                continue;
            }

            // Kategoriezuordnung
            $category = match ($categoryId) {
                '83' => 'Branchennews',
                '81' => 'Presseinformationen',
                '82' => 'Rechtsprechung',
                default => null
            };

            if (!$category) {
                echo "Unbekannte Kategorie ($categoryId), wird übersprungen.\n";
                continue;
            }

            $dateIso = $entry->getAttribute('data-date');
            $titleNode = $xpath->query('.//h3[contains(@class,"ele-entry-title")]/a', $entry)->item(0);
            if (!$titleNode) continue;

            $title = trim($titleNode->textContent);
            $href = $titleNode->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://bdgu.de' . $href;

            $articleDate = $this->resolveDate($dateIso, $link);
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
                'link' => $link,
                'category' => $category
            ];
        }

        return $results;
    }

    private function resolveDate($dataAttr, $link) {
        if ($dataAttr && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $dataAttr)) {
            return substr($dataAttr, 0, 10) . ' 00:00:00';
        }

        // Fallback: Detailseite
        $html = @file_get_contents($link);
        if (!$html) return null;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $timeNode = $xpath->query('//time[contains(@class,"entry-date")]')->item(0);
        if ($timeNode && $timeNode->hasAttribute('datetime')) {
            $dateRaw = $timeNode->getAttribute('datetime');
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateRaw)) {
                return substr($dateRaw, 0, 10) . ' 00:00:00';
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
