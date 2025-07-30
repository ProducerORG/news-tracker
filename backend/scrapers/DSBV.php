<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class DSBV {
    public function fetch() {
        $sourceName = 'DSBV';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo "Seite nicht erreichbar: $baseUrl\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $articles = $xpath->query('//main//article');

        if ($articles->length === 0) {
            echo "Keine Artikel gefunden.\n";
            return [];
        }

        $results = [];
        foreach ($articles as $article) {
            $titleNode = $xpath->query('.//header/h1', $article)->item(0);
            $dateNode = $xpath->query('.//section//p[1]', $article)->item(0); // korrekter kontext

            if (!$titleNode || !$dateNode) {
                echo "Titel oder Datum fehlt – Artikel wird übersprungen.\n";
                continue;
            }

            $title = trim(strip_tags($titleNode->textContent));
            $articleDate = $this->extractDateFromText($dateNode->textContent);
            if (!$articleDate) {
                echo "Kein Datum erkennbar – Artikel wird übersprungen.\n";
                continue;
            }

            $dateTime = new DateTime($articleDate);
            $limitDate = new DateTime('-30 weeks');
            if ($dateTime < $limitDate) {
                echo "Übersprungen (zu alt): {$articleDate}\n";
                continue;
            }

            $linkHash = md5($title);
            $link = $baseUrl . '#article-' . $linkHash;

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

    private function extractDateFromText($text) {
        $text = str_replace(['&nbsp;', ' '], ' ', $text); // geschützte Leerzeichen ersetzen
        $text = trim($text);

        if (preg_match('/(\d{1,2})\.?\s*([a-zäöüA-ZÄÖÜ]+)\s+(\d{4})/u', $text, $matches)) {
            $months = [
                'januar' => '01', 'jan' => '01',
                'februar' => '02', 'feb' => '02',
                'märz' => '03', 'maerz' => '03',
                'april' => '04',
                'mai' => '05',
                'juni' => '06',
                'juli' => '07',
                'august' => '08',
                'september' => '09', 'sept' => '09',
                'oktober' => '10', 'okt' => '10',
                'november' => '11', 'nov' => '11',
                'dezember' => '12', 'dez' => '12'
            ];
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthText = strtolower(trim($matches[2]));
            $month = $months[$monthText] ?? null;
            $year = $matches[3];

            if ($month) {
                return "$year-$month-$day 00:00:00";
            }
        }

        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $d)) {
            return "$d[3]-$d[2]-$d[1] 00:00:00";
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