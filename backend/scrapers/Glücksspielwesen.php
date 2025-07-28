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
        $page = 1;
        $maxPages = 10;

        while ($page <= $maxPages) {
            $url = $baseUrl . ($page > 1 ? '/page/' . $page . '/' : '/');
            echo "Lade Seite: $url\n";

            $html = $this->getHtml($url);
            if (!$html) {
                echo "Seite $url nicht erreichbar. Beende.\n";
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $entries = $xpath->query('//article[contains(@class,"post")]');

            if ($entries->length === 0) {
                echo "Keine Artikel auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//h2[@class="entry-title"]/a', $entry)->item(0);
                $dateNode = $xpath->query('.//time', $entry)->item(0);

                if (!$titleNode || !$dateNode) {
                    continue;
                }

                $title = trim($titleNode->textContent);
                $link = $titleNode->getAttribute('href');
                $dateRaw = $dateNode->getAttribute('datetime');
                $dateTime = DateTime::createFromFormat('Y-m-d\TH:i:sP', $dateRaw);

                if (!$dateTime) {
                    echo "Datum unlesbar für: $link\n";
                    continue;
                }

                $limitDate = new DateTime('-6 weeks');
                if ($dateTime < $limitDate) {
                    echo "Übersprungen (zu alt): {$dateTime->format('Y-m-d')}\n";
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

    private function getHtml($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Bei Problemen mit SSL-Zertifikaten
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT => 10
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false || $httpCode !== 200) {
            echo "Fehler beim Laden von $url (HTTP $httpCode)\n";
            curl_close($ch);
            return null;
        }
        curl_close($ch);
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
