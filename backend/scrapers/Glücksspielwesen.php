<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class Glücksspielwesen {
    public function fetch() {
        $sourceName = 'Glücksspielwesen';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite nicht erreichbar: $baseUrl\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Artikel befinden sich meist im Slider (rs-slide)
        $entries = $xpath->query('//rs-slide');

        if ($entries->length === 0) {
            echo "Keine Artikel gefunden auf Startseite.\n";
            return [];
        }

        foreach ($entries as $entry) {
            $title = $entry->getAttribute('data-title');
            $link = $xpath->query('.//a[contains(@class,"rev-btn")]', $entry)->item(0)?->getAttribute('href');
            $dateText = $xpath->query('.//rs-layer[contains(@id,"layer-5")]', $entry)->item(0)?->textContent;

            if (!$title || !$link) {
                echo "Eintrag unvollständig, übersprungen.\n";
                continue;
            }

            $date = $this->parseGermanDate($dateText);
            if (!$date) {
                echo "Kein gültiges Datum: \"$dateText\" bei $link\n";
                continue;
            }

            $limitDate = new DateTime('-6 weeks');
            if ($date < $limitDate) {
                echo "Übersprungen (zu alt): {$date->format('Y-m-d')}\n";
                continue;
            }

            if ($this->existsPost($title, $link)) {
                echo "Übersprungen (bereits vorhanden): $title\n";
                continue;
            }

            $this->insertPost($date->format('Y-m-d H:i:s'), $source['id'], $title, $link);
            $results[] = [
                'date' => $date->format('Y-m-d H:i:s'),
                'title' => $title,
                'link' => $link
            ];
        }

        return $results;
    }

    private function parseGermanDate($text) {
        $text = trim($text);
        if (preg_match('/\d{1,2}\.\s?[A-Za-zäöüÄÖÜ]+\s?\d{4}/u', $text, $match)) {
            $months = [
                'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
                'Mai' => '05', 'Juni' => '06', 'Juli' => '07', 'August' => '08',
                'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
            ];
            foreach ($months as $name => $num) {
                if (stripos($match[0], $name) !== false) {
                    $parts = preg_split('/\s+/', str_replace('.', '', $match[0]));
                    $day = str_pad(preg_replace('/\D/', '', $parts[0]), 2, '0', STR_PAD_LEFT);
                    $month = $num;
                    $year = $parts[2] ?? date('Y');
                    return DateTime::createFromFormat('Y-m-d H:i:s', "$year-$month-$day 00:00:00");
                }
            }
        }

        // Fallback: auch 1.7.2025 etc.
        if (preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $text, $match)) {
            return DateTime::createFromFormat('d.m.Y H:i:s', $match[0] . ' 00:00:00');
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
