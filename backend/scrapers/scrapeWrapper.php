<?php
require_once __DIR__ . '/../../frontend/public/api.php';

$start = microtime(true);

echo "==== Scraper gestartet: " . date('Y-m-d H:i:s') . " ====\n\n";

function fetchActiveSources() {
    $sourcesJson = supabaseRequest('GET', 'sources?select=*&active=eq.true');
    return json_decode($sourcesJson, true);
}

try {
    $activeSources = fetchActiveSources();

    if (empty($activeSources)) {
        echo "Keine aktiven Quellen gefunden. Abbruch.\n";
        exit;
    }

    foreach ($activeSources as $source) {
        echo "------------------------------------\n";
        echo "Verarbeite Quelle: {$source['name']} (UUID: {$source['id']})\n";

        $classFile = __DIR__ . "/{$source['name']}.php";
        if (!file_exists($classFile)) {
            echo "FEHLER: Scraper-Datei {$classFile} nicht gefunden. Quelle wird übersprungen.\n";
            continue;
        }

        require_once $classFile;

        if (!class_exists($source['name'])) {
            echo "FEHLER: Klasse {$source['name']} nicht gefunden. Quelle wird übersprungen.\n";
            continue;
        }

        echo "Starte Scraper...\n";

        $scraper = new $source['name']();
        $results = $scraper->fetch();

        $count = is_array($results) ? count($results) : 0;
        echo "Gefundene Einträge: {$count}\n";

        if ($count > 0) {
            foreach ($results as $r) {
                echo "- {$r['date']} | {$r['title']}\n";
            }
        }

        echo "Quelle {$source['name']} abgeschlossen.\n";
    }

} catch (Exception $e) {
    echo "FEHLER während der Ausführung: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$end = microtime(true);
$duration = round($end - $start, 2);

echo "\n==== Scraper beendet nach {$duration} Sekunden ====\n";
