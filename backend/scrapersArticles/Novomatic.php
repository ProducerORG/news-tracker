<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n"
        ]
    ];
    $context = stream_context_create($options);
    $html = @file_get_contents($url, false, $context);
    if (!$html) return '';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Besser gezielt auf konkreten Artikeltext
    $contentNode = $xpath->query('//div[contains(@class, "text-formatted")]')->item(0);
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
