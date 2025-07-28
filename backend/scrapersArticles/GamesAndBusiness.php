<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);
    $html = @file_get_contents($url);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Artikelinhalt liegt in div mit class="content-single-post"
    $contentNode = $xpath->query('//div[contains(@class, "content-single-post")]')->item(0);
    if (!$contentNode) return '';

    // Entferne irrelevante Tags
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript', 'form', 'img', 'svg', 'ins'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Extrahiere nur den tatsächlichen Haupttext:
    // z. B. alles innerhalb des div.col-md-8 mit p und h6 Tags
    $text = '';
    $paragraphs = $xpath->query('.//div[contains(@class,"col-md-8")]//p | .//div[contains(@class,"col-md-8")]//h6', $contentNode);
    foreach ($paragraphs as $node) {
        $text .= $node->textContent . "\n\n";
    }

    // Bereinige Whitespace
    $text = trim($text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{2,}/', "\n\n", $text);

    return $text;
}