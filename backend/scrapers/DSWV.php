<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class DSWV {
    public function fetch() {
        $sourceName = 'DSWV';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Quelle $sourceName nicht gefunden.\n";
            return [];
        }

        echo "Verarbeite Quelle: {$sourceName} (UUID: {$source['id']})\n";
        $results = [];

        $pages = [
            'https://www.dswv.de/',             // Hauptseite
            'https://www.dswv.de/presse/'       // Pressebereich
        ];

        foreach ($pages as $url) {
            echo "Lade Seite: $url\n";
            $html = @file_get_contents($url);
            if (!$html) {
                echo "Fehler beim Laden von $url\n";
                continue;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            if (strpos($url, '/presse') !== false) {
                // Presse-Seite mit klarer Struktur: <a><time>...<h3>...</a>
                $entries = $xpath->query('//a[.//time[@datetime]]');

                foreach ($entries as $entry) {
                    $titleNode = $xpath->query('.//h3', $entry)->item(0);
                    $dateNode = $xpath->query('.//time[@datetime]', $entry)->item(0);
                    if (!$titleNode || !$dateNode) continue;

                    $title = trim($titleNode->textContent);
                    $link = 'https://www.dswv.de' . $entry->getAttribute('href');
                    $dateRaw = $dateNode->getAttribute('datetime');

                    $articleDate = $this->parseGermanDate($dateRaw);
                    if (!$articleDate) {
                        echo "Kein Datum interpretierbar: $dateRaw\n";
                        continue;
                    }

                    $this->processEntry($articleDate, $title, $link, $source, $results);
                }

            } else {
                // Hauptseite: keine sichtbaren Datumsangaben → Einzelartikel prüfen
                $entries = $xpath->query('//swiper-slide//a[@href]');
                foreach ($entries as $entry) {
                    $link = 'https://www.dswv.de' . $entry->getAttribute('href');
                    $titleNode = $xpath->query('.//div[contains(@class,"truncate")]', $entry)->item(0);
                    if (!$titleNode) continue;

                    $title = trim($titleNode->textContent);
                    $articleDate = $this->fetchArticleDate($link);
                    if (!$articleDate) {
                        echo "Kein Datum gefunden für: $link\n";
                        continue;
                    }

                    $this->processEntry($articleDate, $title, $link, $source, $results);
                }
            }
        }

        echo "Gefundene Einträge: " . count($results) . "\n";
        foreach ($results as $r) {
            echo "- {$r['date']} | {$r['title']}\n";
        }
        return $results;
    }

    private function fetchArticleDate($link) {
        $html = @file_get_contents($link);
        if (!$html) return null;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Versuche <time datetime="...">
        $timeNode = $xpath->query('//time[@datetime]')->item(0);
        if ($timeNode) {
            return $this->parseGermanDate($timeNode->getAttribute('datetime'));
        }

        // Versuche <meta name="date" content="2025-07-01">
        $metaNode = $xpath->query('//meta[@name="date"]')->item(0);
        if ($metaNode) {
            $date = $metaNode->getAttribute('content');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $date . ' 00:00:00';
            }
        }

        return null;
    }

    private function parseGermanDate($dateStr) {
        $months = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04', 'Mai' => '05', 'Juni' => '06',
            'Juli' => '07', 'August' => '08', 'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
        ];

        if (preg_match('/(\d{1,2})\.?\s*([A-Za-zäöüÄÖÜ]+)\s*(\d{4})/u', $dateStr, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = $months[$m[2]] ?? null;
            $year = $m[3];
            if ($month) {
                return "$year-$month-$day 00:00:00";
            }
        }

        if (preg_match('/\d{4}-\d{2}-\d{2}/', $dateStr)) {
            return $dateStr . ' 00:00:00';
        }

        return null;
    }

    private function processEntry($articleDate, $title, $link, $source, &$results) {
        $dateTime = new DateTime($articleDate);
        $limitDate = new DateTime('-6 weeks');
        if ($dateTime < $limitDate) {
            echo "Übersprungen (zu alt): $articleDate\n";
            return;
        }

        if ($this->existsPost($title, $link)) {
            echo "Übersprungen (bereits vorhanden): $title\n";
            return;
        }

        $this->insertPost($dateTime->format('Y-m-d H:i:s'), $source['id'], $title, $link);
        $results[] = [
            'date' => $dateTime->format('Y-m-d H:i:s'),
            'title' => $title,
            'link' => $link
        ];
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
