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

        $pages = [
            'https://www.dswv.de/',
            'https://www.dswv.de/presse/'
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

            // Gemeinsamer Query für Artikel-Links auf beiden Seiten
            $entries = $xpath->query('//a[contains(@href,"/")]');

            foreach ($entries as $entry) {
                $href = $entry->getAttribute('href');
                if (strpos($href, '/kontakt/') !== false || strpos($href, '/jobs/') !== false || strpos($href, '/impressum') !== false) {
                    continue;
                }

                $link = (strpos($href, 'http') === 0) ? $href : 'https://www.dswv.de' . $href;
                $title = trim($entry->textContent);

                if (strlen($title) < 10 || empty($title)) {
                    continue;
                }

                // Versuche, das Datum im Elternelement zu finden
                $parent = $entry->parentNode;
                $dateNode = null;

                while ($parent && !$dateNode) {
                    $dateNode = (new DOMXPath($dom))->query('.//time[@datetime]', $parent)->item(0);
                    $parent = $parent->parentNode;
                }

                if ($dateNode) {
                    $dateText = trim($dateNode->getAttribute('datetime'));

                    // Umwandlung von deutschem Datumsformat in ISO
                    if (preg_match('/\d{1,2}\.\s*[A-Za-zäöüÄÖÜ]+\.?\s*\d{4}/u', $dateText)) {
                        $dateText = str_replace(
                            ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
                            ['01', '02', '03', '04', '05', '06',
                            '07', '08', '09', '10', '11', '12'],
                            $dateText
                        );

                        $parts = explode('.', preg_replace('/\s+/', ' ', $dateText));
                        if (count($parts) === 3) {
                            $articleDate = sprintf('%04d-%02d-%02d 00:00:00', (int)trim($parts[2]), (int)trim($parts[1]), (int)trim($parts[0]));
                        } else {
                            $articleDate = null;
                        }
                    } elseif (preg_match('/\d{4}-\d{2}-\d{2}/', $dateText)) {
                        $articleDate = $dateText . ' 00:00:00';
                    } else {
                        $articleDate = null;
                    }
                } else {
                    $articleDate = $this->fetchArticleDate($link); // Fallback
                }

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

        // Suche nach <time datetime="...">-Element
        $timeNode = $xpath->query('//time[@datetime]')->item(0);
        if ($timeNode) {
            $raw = trim($timeNode->getAttribute('datetime'));
            // Format wie "27. Juni 2025" oder "2025-07-27"
            if (preg_match('/\d{2}\.\s*[A-Za-zäöüÄÖÜ]+\.?\s*\d{4}/u', $raw)) {
                $germanDate = DateTime::createFromFormat('d. M Y', $raw);
                if (!$germanDate) {
                    $raw = str_replace(['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                                        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
                                       ['01', '02', '03', '04', '05', '06',
                                        '07', '08', '09', '10', '11', '12'], $raw);
                    $raw = preg_replace('/\s+/', ' ', $raw);
                    if (preg_match('/(\d{2})\. (\d{2}) (\d{4})/', $raw, $matches)) {
                        return "{$matches[3]}-{$matches[2]}-{$matches[1]} 00:00:00";
                    }
                } else {
                    return $germanDate->format('Y-m-d') . ' 00:00:00';
                }
            } elseif (preg_match('/\d{4}-\d{2}-\d{2}/', $raw)) {
                return $raw . ' 00:00:00';
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
