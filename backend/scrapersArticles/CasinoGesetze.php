<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);
    $html = @file_get_contents($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Der Artikelinhalt befindet sich im div mit id="article"
    $contentNode = $xpath->query('//div[@id="article"]')->item(0);
    if (!$contentNode) return '';

    // Entferne irrelevante Tags innerhalb des Artikels
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript', 'form', 'img', 'svg'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Extrahiere nur sichtbaren Textinhalt
    $text = trim($contentNode->textContent);
    $text = preg_replace('/\s+/', ' ', $text);

    return $text;
}
