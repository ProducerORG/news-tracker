<?php
require_once __DIR__ . '/../../frontend/public/api.php';

class Eurojust {
    public function fetch() {
        $sourceName = 'Eurojust';
        $source = $this->fetchSourceByName($sourceName);

        if (!$source) {
            echo "Source $sourceName not found.\n";
            return [];
        }

        $baseUrl = rtrim($source['url'], '/');
        echo "Verwende URL aus DB: {$baseUrl}\n";

        $results = [];
        $page = 0;
        $maxPages = 10;

        while ($page < $maxPages) {
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

            // Jeder Artikel ist in einem .views-row Container
            $entries = $xpath->query('//div[contains(@class,"views-row")]');
            if ($entries->length === 0) {
                echo "Keine Einträge auf Seite $page gefunden. Beende.\n";
                break;
            }

            foreach ($entries as $entry) {
                $titleNode = $xpath->query('.//div[contains(@class,"field--name-node-title")]//a', $entry)->item(0);
                $dateNode = $xpath->query('.//span[contains(@class,"info-date")]', $entry)->item(0);

                if ($titleNode && $dateNode) {
                    $title = trim($titleNode->textContent);
                    $href = $titleNode->getAttribute('href');
                    $link = (strpos($href, 'http') === 0) ? $href : 'https://www.eurojust.europa.eu' . $href;

                    $dateRaw = trim($dateNode->textContent);
                    $dateTime = DateTime::createFromFormat('d F Y', $dateRaw);
                    if (!$dateTime) {
                        $dateTime = DateTime::createFromFormat('d M Y', $dateRaw); // falls Monatsname gekürzt ist
                    }
                    if (!$dateTime) {
                        $dateTime = DateTime::createFromFormat('d.m.Y', $dateRaw); // zusätzlicher Fallback
                    }

                    if (!$dateTime) {
                        echo "Kein lesbares Datum für: $link\n";
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
