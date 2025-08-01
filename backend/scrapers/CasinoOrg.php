<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class CasinoOrg {
    public function fetch() {
        $sourceName = 'CasinoOrg';
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
            $url = $page === 1 ? $baseUrl . '/' : $baseUrl . '/page/' . $page . '/';
            echo "Lade Seite: $url\n";
            $html = $this->curlGet($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $entries = $xpath->query('//div[contains(@class,"blog-post-item")]');
            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//h4[contains(@class,"blog-post-item__title")]/a', $entry)->item(0);
                $dateNode = $xpath->query('.//span[contains(@class,"blog-post-item__date")]', $entry)->item(0);

                if ($titleNode && $dateNode) {
                    $title = trim($titleNode->textContent);
                    $href = trim($titleNode->getAttribute('href'));
                    $link = (strpos($href, 'http') === 0) ? $href : 'https://www.casino.org' . $href;

                    $dateRaw = trim($dateNode->textContent);
                    $dateTime = DateTime::createFromFormat('d/m/Y', $dateRaw);
                    if (!$dateTime) {
                        echo "Ungültiges Datum: {$dateRaw} bei $link. Überspringe.\n";
                        continue;
                    }

                    $formattedDate = $dateTime->format('Y-m-d H:i:s');
                    $limitDate = new DateTime('-6 weeks');
                    if ($dateTime < $limitDate) {
                        echo "Übersprungen (zu alt): {$formattedDate}\n";
                        continue;
                    }

                    if ($this->existsPost($title, $link)) {
                        echo "Übersprungen (bereits vorhanden): $title\n";
                        continue;
                    }

                    $this->insertPost($formattedDate, $source['id'], $title, $link);
                    $results[] = [
                        'date' => $formattedDate,
                        'title' => $title,
                        'link' => $link
                    ];
                }
            }
            $page++;
        }

        return $results;
    }

    private function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:102.0) Gecko/20100101 Firefox/102.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_COOKIEJAR => '/tmp/casinoorg_cookies.txt',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive',
                'Referer: https://www.google.com/',
                'Cache-Control: max-age=0',
            ],
        ]);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "cURL-Fehler bei $url: " . curl_error($ch) . "\n";
            return false;
        }
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($statusCode >= 400) {
            echo "HTTP-Fehler $statusCode bei $url\n";
            return false;
        }
        return $result;
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
