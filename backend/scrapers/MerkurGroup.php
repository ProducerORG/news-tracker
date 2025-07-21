<?php
class MerkurGroup {
    const SOURCE_NAME = 'MerkurGroup';

    public static function fetch(PDO $db) {
        $stmt = $db->prepare("SELECT id, url FROM sources WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => self::SOURCE_NAME]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$source) {
            echo "FEHLER: Quelle " . self::SOURCE_NAME . " nicht in der DB gefunden.\n";
            return [];
        }

        echo "Nutze URL aus DB für " . self::SOURCE_NAME . ": {$source['url']}\n";

        $html = @file_get_contents($source['url']);
        if ($html === false) {
            echo "FEHLER: Konnte URL nicht abrufen: {$source['url']}\n";
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $entries = $xpath->query('//div[@id="ausgabe"]/div');
        $results = [];

        foreach ($entries as $entry) {
            $dateNode = $xpath->query('.//span[contains(@class,"datum")]', $entry)->item(0);
            $titleNode = $xpath->query('.//h4/a', $entry)->item(0);
            $linkNode = $xpath->query('.//h4/a', $entry)->item(0);

            if ($dateNode && $titleNode && $linkNode) {
                $dateRaw = trim($dateNode->textContent);
                $dateParts = explode('.', $dateRaw);
                if (count($dateParts) === 3) {
                    $dateFormatted = sprintf('%04d-%02d-%02d 00:00:00', $dateParts[2], $dateParts[1], $dateParts[0]);
                } else {
                    $dateFormatted = date('Y-m-d H:i:s');
                }

                $title = trim($titleNode->textContent);
                $href = $linkNode->getAttribute('href');

                // Absoluter Link aus der DB-URL bilden
                if (strpos($href, 'http') === 0) {
                    $link = $href;
                } else {
                    $link = rtrim($source['url'], '/') . '/' . ltrim($href, '/');
                }

                $stmt = $db->prepare("INSERT INTO posts (date, source_id, title, link, deleted, created_at)
                                      VALUES (:date, :source_id, :title, :link, FALSE, NOW())");
                $stmt->execute([
                    'date' => $dateFormatted,
                    'source_id' => $source['id'],
                    'title' => $title,
                    'link' => $link
                ]);

                $results[] = [
                    'date' => $dateFormatted,
                    'title' => $title,
                    'link' => $link
                ];
            }
        }

        echo "Erfolgreich verarbeitet für " . self::SOURCE_NAME . ": " . count($results) . " Einträge.\n";
        return $results;
    }
}
