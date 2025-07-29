<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class DSWV {
    public function fetch() {
        $sourceName = 'DSWV';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $urls = [
            'https://www.dswv.de/',
            'https://www.dswv.de/presse/'
        ];

        $results = [];

        foreach ($urls as $url) {
            echo "Lade URL: $url\n";
            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Überspringe.\n";
                continue;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            // Suche alle <a>-Container mit h3-Titel darin
            $aNodes = $xpath->query('//a[.//h3]');

            if ($aNodes->length === 0) {
                echo "Keine Artikel auf $url gefunden.\n";
                continue;
            }

            foreach ($aNodes as $aTag) {
                $titleNode = $xpath->query('.//h3', $aTag)->item(0);
                if (!$titleNode) continue;

                $title = trim($titleNode->textContent);
                $href = $aTag->getAttribute('href');
                $link = strpos($href, 'http') === 0 ? $href : 'https://www.dswv.de' . $href;

                $timeNode = $xpath->query('.//time', $aTag)->item(0);
                $dateText = $timeNode ? trim($timeNode->textContent) : null;
                $articleDate = $this->parseDate($dateText);

                if (!$articleDate) {
                    echo "Kein gültiges Datum für: $link, wird übersprungen.\n";
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

        return $results;
    }

    private function parseDate($text) {
        if (!$text) return null;

        // Standardformate prüfen: "27. Juni 2025" oder "15. Feb. 2024"
        $replacements = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03',
            'April' => '04', 'Mai' => '05', 'Juni' => '06',
            'Juli' => '07', 'August' => '08', 'September' => '09',
            'Oktober' => '10', 'November' => '11', 'Dezember' => '12',
            'Jan.' => '01', 'Feb.' => '02', 'Mär.' => '03',
            'Apr.' => '04', 'Mai' => '05', 'Jun.' => '06',
            'Jul.' => '07', 'Aug.' => '08', 'Sep.' => '09',
            'Okt.' => '10', 'Nov.' => '11', 'Dez.' => '12'
        ];

        foreach ($replacements as $k => $v) {
            if (stripos($text, $k) !== false) {
                $text = str_ireplace($k, $v, $text);
                break;
            }
        }

        if (preg_match('/(\d{1,2})\.\s*(\d{2})\.\s*(\d{4})/', $text, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/(\d{1,2})\.\s*(\d{2})\s*(\d{4})/', $text, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/(\d{1,2})\.\s*(\d{2})/', $text, $m)) {
            $year = date('Y');
            return sprintf('%04d-%02d-%02d 00:00:00', $year, $m[2], $m[1]);
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
