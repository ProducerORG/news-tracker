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

        $results = [];
        $page = 0;
        $step = 6;
        $maxPages = 10;

        while ($page < $maxPages) {
            $offset = $page * $step;
            $url = $baseUrl . '?start=' . $offset;
            echo "Lade Seite: $url\n";

            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $entries = $xpath->query('//div[contains(@class,"el-item")]');

            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//div[contains(@class,"el-title")]', $entry)->item(0);
                $dateNode  = $xpath->query('.//div[contains(@class,"el-meta")]', $entry)->item(0);
                $linkNode  = $xpath->query('.//a[contains(@class,"el-link")]', $entry)->item(0);

                if ($titleNode && $dateNode && $linkNode) {
                    $title = trim($titleNode->textContent);
                    $dateText = trim($dateNode->textContent);
                    $href = $linkNode->getAttribute('href');
                    $link = (strpos($href, 'http') === 0) ? $href : 'https://www.gluecksspiel-behoerde.de' . $href;

                    $articleDate = $this->parseGermanDate($dateText);
                    if (!$articleDate) {
                        echo "Kein Datum erkannt für: $link, wird übersprungen.\n";
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

    private function parseGermanDate($text) {
        // Erwarte Format wie: 02. Juli 2025
        $months = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
            'Mai' => '05', 'Juni' => '06', 'Juli' => '07', 'August' => '08',
            'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
        ];

        if (preg_match('/(\d{1,2})\. (\p{L}+) (\d{4})/u', $text, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = $months[$matches[2]] ?? null;
            $year = $matches[3];
            if ($month) {
                return "$year-$month-$day 00:00:00";
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
