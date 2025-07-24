<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);
    $html = @file_get_contents($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Ziel: Nur der Inhaltsblock innerhalb <div class="entry-content">
    $contentNode = $xpath->query('//div[contains(@class, "entry-content")]')->item(0);
    if (!$contentNode) return '';

    // Alle <script>, <style>, <figure>, <figcaption>, Links, Quellen usw. entfernen
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Den rohen Text extrahieren
    $text = trim($contentNode->textContent);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}
