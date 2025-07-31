<?php
function scrapeArticle(string $url): string {
    libxml_use_internal_errors(true);

    // HTML laden via cURL
    $html = fetchHtmlWithCurl($url);
    if (!$html || strlen(trim($html)) < 100) {
        file_put_contents('/tmp/ludologie_error_fetch.log', "Fehler beim Laden der URL: $url\n");
        return '';
    }

    // DOM initialisieren
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Artikelinhalt finden
    $matches = $xpath->query('//div[contains(@class, "news-text-wrap")]');
    if (!$matches || $matches->length === 0) {
        file_put_contents('/tmp/ludologie_error_xpath.log', "Kein Textcontainer gefunden: $url\n");
        return '';
    }

    $contentNode = $matches->item(0);
    if (!$contentNode) {
        file_put_contents('/tmp/ludologie_error_dom.log', "DOM-Zugriff fehlgeschlagen: $url\n");
        return '';
    }

    // Störende Tags entfernen
    $removeTags = ['script', 'style', 'figure', 'figcaption', 'noscript', 'form', 'img', 'svg'];
    foreach ($removeTags as $tag) {
        $nodes = $contentNode->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    // Text extrahieren
    $text = trim($contentNode->textContent);
    $text = preg_replace('/\s+/', ' ', $text);

    // Protokollieren, wenn Text zu kurz ist
    if (strlen($text) < 100) {
        file_put_contents('/tmp/ludologie_error_text.log', "Zu kurzer Text bei $url:\n$text\n");
        return '';
    }

    // Optionaler Debug-Dump
    file_put_contents('/tmp/ludologie_last_success.txt', $text);

    return $text;
}

function fetchHtmlWithCurl(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NewsScraper/1.0)',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400 || !$html) {
        file_put_contents('/tmp/ludologie_error_curl.log', "Fehler bei cURL [$code] $url\n$err\n");
        return '';
    }

    return $html;
}
