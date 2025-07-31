<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class DGGS {
    public function fetch() {
        $sourceName = 'DGGS';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        $baseDomain = 'https://www.dggs-online.de';
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 1;
        $maxPages = 10;

        while ($page <= $maxPages) {
            $url = $baseUrl . '?page=' . $page;
            echo "Lade Seite: $url\n";

            $html = @file_get_contents($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $cards = $xpath->query('//div[contains(@class,"group relative grid") and .//a[contains(@href,"/news/")]]');

            if ($cards->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($cards as $card) {
                $linkNode = $xpath->query('.//a[contains(@href,"/news/")]', $card)->item(0);
                $titleNode = $xpath->query('.//a//span[@class="sr-only"]', $card)->item(0);
                $dateNodes = $xpath->query('.//div[contains(@class,"text-muted")]', $card);

                if (!$linkNode || !$titleNode || $dateNodes->length < 2) {
                    echo "Unvollständige Daten – wird übersprungen.\n";
                    continue;
                }

                $title = trim($titleNode->textContent);
                $href = $linkNode->getAttribute('href');
                $link = (strpos($href, 'http') === 0) ? $href : $baseDomain . $href;

                $dateText = trim($dateNodes->item($dateNodes->length - 1)->textContent);
                $articleDate = $this->parseGermanDate($dateText);

                if (!$articleDate) {
                    echo "Fehlerhafte Datumsangabe: \"$dateText\" ($link), wird übersprungen.\n";
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

            $page++;
        }

        return $results;
    }

    private function parseGermanDate($text) {
        $months = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
            'Mai' => '05', 'Juni' => '06', 'Juli' => '07', 'August' => '08',
            'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
        ];

        // Entferne optionalen Wochentag z. B. "Montag, "
        $text = preg_replace('/^\s*\w+,\s*/u', '', $text);

        if (preg_match('/(\d{1,2})\.\s?(\w+)\s+(\d{4})/', $text, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = $months[$m[2]] ?? null;
            $year = $m[3];
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
