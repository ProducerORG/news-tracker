<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);
    $html = @file_get_contents($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Ziel: Inhalte im Textbereich des Artikels herausholen
    // Konkrete divs: id="textmodul" oder class="white-bg" innerhalb davon
    $contentNode = $xpath->query('//div[@id="textmodul"]')->item(0);
    if (!$contentNode) {
        // Fallback: versuche .white-bg direkt
        $contentNode = $xpath->query('//div[contains(@class, "white-bg")]')->item(0);
    }
    if (!$contentNode) return '';

    // Alle störenden Tags entfernen
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript', 'form', 'img', 'svg'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Text extrahieren und bereinigen
    $text = trim($contentNode->textContent);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}
