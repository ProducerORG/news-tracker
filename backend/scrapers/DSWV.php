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

        $results = [];

        // Hauptseite
        $html = @file_get_contents('https://www.dswv.de/');
        if ($html) {
            echo "Lade Seite: https://www.dswv.de/\n";
            $results = array_merge($results, $this->parseMainPage($html, $source));
        }

        // Presse-Unterseite
        $html = @file_get_contents('https://www.dswv.de/presse/');
        if ($html) {
            echo "Lade Seite: https://www.dswv.de/presse/\n";
            $results = array_merge($results, $this->parsePressePage($html, $source));
        }

        return $results;
    }

    private function parseMainPage($html, $source) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $entries = $xpath->query('//swiper-slide//a');
        $results = [];

        foreach ($entries as $entry) {
            $href = $entry->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://www.dswv.de' . $href;

            $titleNode = $xpath->query('.//div[contains(@class,"text")]', $entry)->item(0);
            if (!$titleNode) continue;

            $title = trim($titleNode->textContent);
            if (strlen($title) < 10) continue;

            $articleDate = $this->fetchArticleDate($link);
            if (!$articleDate) {
                echo "Kein Datum gefunden für: $link\n";
                continue;
            }

            $dateTime = new DateTime($articleDate);
            $limitDate = new DateTime('-6 weeks');
            if ($dateTime < $limitDate) {
                echo "Übersprungen (zu alt): $articleDate\n";
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

    private function parsePressePage($html, $source) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $entries = $xpath->query('//a[contains(@class, "block") and .//time]');
        $results = [];

        foreach ($entries as $entry) {
            $href = $entry->getAttribute('href');
            $link = (strpos($href, 'http') === 0) ? $href : 'https://www.dswv.de' . $href;

            $titleNode = $xpath->query('.//h3', $entry)->item(0);
            if (!$titleNode) continue;

            $title = trim($titleNode->textContent);
            if (strlen($title) < 10) continue;

            $dateNode = $xpath->query('.//time', $entry)->item(0);
            if (!$dateNode) continue;

            $dateRaw = $dateNode->getAttribute('datetime');
            $articleDate = $this->parseGermanDate($dateRaw);
            if (!$articleDate) {
                echo "Kein gültiges Datum in Teasereintrag: $link\n";
                continue;
            }

            $dateTime = new DateTime($articleDate);
            $limitDate = new DateTime('-6 weeks');
            if ($dateTime < $limitDate) {
                echo "Übersprungen (zu alt): $articleDate\n";
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

    private function fetchArticleDate($link) {
        $html = @file_get_contents($link);
        if (!$html) {
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $dateNode = $xpath->query('//time[@datetime]')->item(0);
        if ($dateNode) {
            $dateRaw = trim($dateNode->getAttribute('datetime'));
            return $this->parseGermanDate($dateRaw);
        }

        $metaDate = $xpath->query('//meta[@name="date"]')->item(0);
        if ($metaDate) {
            $dateRaw = $metaDate->getAttribute('content');
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $dateRaw)) {
                return $dateRaw . ' 00:00:00';
            }
        }

        return null;
    }

    private function parseGermanDate($text) {
        $text = trim($text);
        $monate = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04', 'Mai' => '05', 'Juni' => '06',
            'Juli' => '07', 'August' => '08', 'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
        ];

        if (preg_match('/(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)\s*(\d{4})/', $text, $m)) {
            $tag = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $monat = $monate[$m[2]] ?? null;
            $jahr = $m[3];
            if ($monat) return "$jahr-$monat-$tag 00:00:00";
        }

        if (preg_match('/\d{4}-\d{2}-\d{2}/', $text)) {
            return $text . ' 00:00:00';
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
