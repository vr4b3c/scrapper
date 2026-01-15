<?php

// Funkce pro získání HTML obsahu z URL
function getHtmlContent($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10, // Timeout na 10 sekund
        ]
    ]);
    return @file_get_contents($url, false, $context);
}

// Funkce pro nalezení odkazů na staré adresy coi.cz
function findOldLinks($html) {
    $pattern = '/https?:\/\/[^\s<>"\']*coi\.cz/';
    preg_match_all($pattern, $html, $matches);
    return $matches[0] ?? [];
}

// Funkce pro extrakci všech odkazů ze stránky
function extractLinks($html, $baseUrl) {
    $pattern = '/href=["\'](\/[^"\']*|https?:\/\/[^"\']*)["\']/i';
    preg_match_all($pattern, $html, $matches);

    $links = [];
    foreach ($matches[1] as $link) {
        if (!preg_match('/^https?:\/\//i', $link)) {
            // Převod relativních URL na absolutní
            $link = rtrim($baseUrl, '/') . '/' . ltrim($link, '/');
        }
        // Filtruj nežádoucí odkazy
        if (!preg_match('/\.(pdf|jpg|jpeg|png|gif|ods|csv|xls|xlsx)$/i', $link) 
            && !preg_match('/wp-json\//i', $link) 
            && strpos($link, $baseUrl) === 0) {
            $links[] = $link;
        }
    }

    return array_unique($links);
}

// Hlavní část skriptu
$baseUrl = "https://coi.gov.cz/";
$visitedUrls = [];
$queue = [$baseUrl];
$results = [];
$countedResults = [];

while (!empty($queue)) {
    $currentUrl = array_shift($queue);

    // Přeskočení již navštívených URL
    if (in_array($currentUrl, $visitedUrls)) {
        continue;
    }
    $visitedUrls[] = $currentUrl;

    echo "Zpracovávám: $currentUrl<br>";

    // Načtení obsahu stránky
    $html = getHtmlContent($currentUrl);
    if ($html === false) {
        echo "Nepodařilo se načíst: $currentUrl<br>";
        continue;
    }

    // Najdi staré odkazy na coi.cz
    $oldLinks = findOldLinks($html);
    foreach ($oldLinks as $oldLink) {
        $key = $currentUrl . '|' . $oldLink;
        if (!isset($countedResults[$key])) {
            $countedResults[$key] = ['page' => $currentUrl, 'link' => $oldLink, 'count' => 0];
        }
        $countedResults[$key]['count']++;
    }

    // Extrahuj nové odkazy a přidej je do fronty
    $newLinks = extractLinks($html, $baseUrl);
    foreach ($newLinks as $newLink) {
        if (!in_array($newLink, $visitedUrls) && strpos($newLink, $baseUrl) === 0) {
            $queue[] = $newLink;
        }
    }
}

// Ulož výsledky do CSV
$csvFile = 'coi_old_links.csv';
$handle = fopen($csvFile, 'w');
if ($handle) {
    fputcsv($handle, ['Page URL', 'Old Link', 'Count']);
    foreach ($countedResults as $row) {
        fputcsv($handle, [$row['page'], $row['link'], $row['count']]);
    }
    fclose($handle);
    echo "CSV soubor byl vytvořen: $csvFile<br>";
} else {
    echo "Nepodařilo se vytvořit CSV soubor.<br>";
}

?>

