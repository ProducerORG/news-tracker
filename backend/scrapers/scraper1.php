<?php
class Scraper1 {
    public static function fetch(PDO $db) {
        $html = file_get_contents('https://example-source-1.com');
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $entries = $xpath->query('//div[@class="news-entry"]');

        foreach ($entries as $entry) {
            $titleNode = $xpath->query('.//h2', $entry)->item(0);
            $linkNode = $xpath->query('.//a', $entry)->item(0);
            if ($titleNode && $linkNode) {
                $title = trim($titleNode->textContent);
                $link = $linkNode->getAttribute('href');
                $date = date('Y-m-d H:i:s');
                $stmt = $db->prepare("INSERT INTO posts (date, source_id, title, link, deleted, created_at)
                                      VALUES (:date, :source_id, :title, :link, FALSE, NOW())");
                $stmt->execute([
                    'date' => $date,
                    'source_id' => 'UUID-scraper1',
                    'title' => $title,
                    'link' => $link
                ]);
            }
        }
    }
}