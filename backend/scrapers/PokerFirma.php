<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class PokerFirma {
    public function fetch() {
        $sourceName = 'PokerFirma';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 1;
        $maxPages = 10;

        while ($page <= $maxPages) {
            $url = ($page === 1) ? $baseUrl : $baseUrl . '/page/' . $page;
            echo "Lade Seite: $url\n";
            $html = $this->fetchUrl($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar oder blockiert. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $entries = $xpath->query('//div[contains(@class,"blog-card")]/parent::a');

            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $link = $entry->getAttribute('href');
                $titleNode = $xpath->query('.//h2[contains(@class,"card-title")]', $entry)->item(0);

                if ($titleNode && $link) {
                    $title = trim($titleNode->textContent);

                    $articleDate = $this->fetchArticleDate($link);
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
                        'link' => $link
                    ];
                }
            }

            $page++;
        }

        return $results;
    }

    private function fetchUrl($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:114.0) Gecko/20100101 Firefox/114.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.5',
            'Referer: https://www.google.com/',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: max-age=0'
        ]);
        $html = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($info['http_code'] !== 200 || !$html) {
            echo "Fehler beim Laden von $url (HTTP {$info['http_code']})";
            if ($error) echo " – cURL-Fehler: $error";
            echo "\n";
            return false;
        }

        return $html;
    }

    private function fetchArticleDate($link) {
        $html = $this->fetchUrl($link);
        if (!$html) {
            echo "Artikel nicht erreichbar: $link\n";
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $dateNode = $xpath->query('//h3[contains(text(), "Kommentare")]')->item(0);
        if ($dateNode && preg_match('/(\d{1,2})\.\s*(\w+)\s*(\d{4})/', $dateNode->textContent, $m)) {
            $months = [
                'januar'=>1,'februar'=>2,'märz'=>3,'april'=>4,'mai'=>5,'juni'=>6,
                'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12
            ];
            $day = (int)$m[1];
            $month = $months[strtolower($m[2])] ?? 0;
            $year = (int)$m[3];
            if ($month > 0) {
                return sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
            }
        }

        $meta = $xpath->query('//meta[@property="article:published_time"]')->item(0);
        if ($meta) {
            $raw = $meta->getAttribute('content');
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                return substr($raw, 0, 10) . ' 00:00:00';
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
