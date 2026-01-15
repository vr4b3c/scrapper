<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// Base URL a stránkování
$baseUrl = 'https://www.realcity.cz/agentury';
$baseDomain = 'https://www.realcity.cz';
$maxPages = 8;

$data = [];

echo "<h2>realcity.cz</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

// Procházení stránek
for ($page = 1; $page <= $maxPages; $page++) {
    $url = $page == 1 ? $baseUrl : "$baseUrl?list-page=$page&list-sort=advertise-desc";
    
    // Stažení HTML obsahu stránky
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $htmlContent = curl_exec($ch);
    curl_close($ch);
    
    if (!$htmlContent) {
        echo "Chyba při stahování stránky: $url<br>";
        continue;
    }
    
    $html = str_get_html($htmlContent);
    
    // Procházení všech odkazů na detail kanceláře
    foreach ($html->find('.media.agency.item a.agency-name') as $link) {
        $relativeUrl = trim($link->href);
        $detailUrl = strpos($relativeUrl, '/') === 0 ? $baseDomain . $relativeUrl : $relativeUrl;
        
        // Stažení HTML obsahu detailu realitky
        $ch = curl_init($detailUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $detailContent = curl_exec($ch);
        curl_close($ch);
        
        if (!$detailContent) {
            echo "Chyba při stahování detailu realitky: $detailUrl<br>";
            continue;
        }
        
        $detailHtml = str_get_html($detailContent);
        
        // Extrakce jména realitky
        $nazev = trim($detailHtml->find('h1', 0)->plaintext ?? '');
        
        // Inicializace proměnných
        $email = '';
        $telefon = '';
        
        // Prohledání hlavního bloku s kontaktními údaji
        foreach ($detailHtml->find('.media.agency .media-body > div') as $div) {
            $text = trim($div->plaintext);
            
            // Vyhledání telefonu
            if (strpos($text, 'Tel.:') !== false) {
                $telefon = trim(str_replace('Tel.:', '', $text));
            }
        }
        
        // Vyhledání emailu v mailto odkazu
        $emailElement = $detailHtml->find('.media.agency .media-body a[href^="mailto:"]', 0);
        if ($emailElement) {
            $email = trim(str_replace('mailto:', '', $emailElement->href));
        }
        
        $data[] = [$nazev, $email, $telefon];
        
        // Výpis do tabulky ihned
        echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
    }
}

echo "</table>";

echo "<p>Počet nalezených záznamů: " . count($data) . "</p>";

// Odstranění duplicit
$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

usort($data, function($a, $b) {
    return strcasecmp($a[0], $b[0]);
});

// Uložení dat do CSV
$csvFile = 'realcity_cz.csv';
$fp = fopen($csvFile, 'w');

// Zápis hlavičky CSV souboru
fputcsv($fp, ['Název', 'E-mail', 'Telefon'], ';');

// Zápis jednotlivých záznamů do CSV
foreach ($data as $radek) {
    fputcsv($fp, $radek, ';');
}

fclose($fp);

echo "<br>Scraper dokončen. Data byla uložena do souboru $csvFile.<br>";

?>

