<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class CardPlayer {
    public function fetch() {
        $sourceName = 'CardPlayer';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName nicht gefunden.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 1;
        $maxPages = 5;

        while ($page <= $maxPages) {
            $url = $baseUrl; // keine Pagination vorhanden
            echo "Lade Seite: $url\n";
            $html = $this->getPage($url);

            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $entries = $xpath->query('//div[contains(@class,"cp-featured-story")]');

            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//h3', $entry)->item(0);
                $linkNode = $xpath->query('.//a', $entry)->item(0);

                if ($titleNode && $linkNode) {
                    $title = trim($titleNode->textContent);
                    $href = $linkNode->getAttribute('href');
                    $link = (strpos($href, 'http') === 0) ? $href : 'https://www.cardplayer.com' . $href;

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

    private function fetchArticleDate($link) {
        $html = $this->getPage($link);
        if (!$html) {
            echo "Artikel nicht erreichbar: $link\n";
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $dateNode = $xpath->query('//time')->item(0);
        if ($dateNode) {
            $dateText = trim($dateNode->textContent);
            if (preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $dateText, $matches)) {
                $germanDate = DateTime::createFromFormat('d.m.Y', $matches[0]);
                if ($germanDate) {
                    return $germanDate->format('Y-m-d') . ' 00:00:00';
                }
            }
        }

        $metaDate = $xpath->query('//meta[@property="article:published_time"]')->item(0);
        if ($metaDate) {
            $dateRaw = $metaDate->getAttribute('content');
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $dateRaw, $matches)) {
                return $matches[0] . ' 00:00:00';
            }
        }

        return null;
    }

    private function getPage($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // nur lokal!
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ],
        ]);
    
        $html = curl_exec($ch);

        if (curl_errno($ch)) {
            echo "cURL-Fehler: " . curl_error($ch) . "\n";
            curl_close($ch);
            return null;
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            echo "HTTP-Status $status für $url\n";
            return null;
        }

        return $html;
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
