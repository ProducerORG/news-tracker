<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);
    $html = @file_get_contents($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Hauptinhalt liegt im div mit class="news-text-wrap"
    $contentNode = $xpath->query('//div[contains(@class, "news-text-wrap")]')->item(0);
    if (!$contentNode) return '';

    // Entferne irrelevante Tags
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript', 'form', 'img', 'svg'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Extrahiere Text
    $text = trim($contentNode->textContent);
    $text = preg_replace('/\s+/', ' ', $text);

    return $text;
}
