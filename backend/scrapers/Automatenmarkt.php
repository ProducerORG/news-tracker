<?php
class Scraper2 {
    public static function fetch(PDO $db) {
        $html = file_get_contents('https://example-source-2.com/news');
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $articles = $xpath->query('//article');

        foreach ($articles as $article) {
            $titleNode = $xpath->query('.//h1', $article)->item(0);
            $linkNode = $xpath->query('.//a', $article)->item(0);
            if ($titleNode && $linkNode) {
                $title = trim($titleNode->textContent);
                $link = $linkNode->getAttribute('href');
                $date = date('Y-m-d H:i:s');
                $stmt = $db->prepare("INSERT INTO posts (date, source_id, title, link, deleted, created_at)
                                      VALUES (:date, :source_id, :title, :link, FALSE, NOW())");
                $stmt->execute([
                    'date' => $date,
                    'source_id' => 'UUID-scraper2',
                    'title' => $title,
                    'link' => $link
                ]);
            }
        }
    }
}