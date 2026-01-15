<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// Base URL a stránkování
$baseUrl = 'https://www.vitio.cz/realitni-kancelare?page=';
$baseDomain = 'https://www.vitio.cz';
$maxPages = 178;

$data = [];

echo "<h2>vitio.cz</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

// Procházení prvních 3 stránek
for ($page = 1; $page <= $maxPages; $page++) {
    $url = $baseUrl . $page;
    
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
    foreach ($html->find('#w1 .agencies__link a.stretched-link') as $link) {
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
        
        // Extrakce e-mailu
        $emailElement = $detailHtml->find('.head-bg .fa-envelope', 0);
        $email = $emailElement ? trim($emailElement->parent()->plaintext) : '';
        
        // Extrakce telefonu
        $telefonElement = $detailHtml->find('.head-bg .fa-phone', 0);
        $telefon = $telefonElement ? trim($telefonElement->parent()->plaintext) : '';
        
        $data[] = [$nazev, $email, $telefon];
        
        // Výpis do tabulky ihned
        echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
    }
}

echo "</table>";

echo "<p>Počet nalezených URL: " . count($data) . "</p>";

$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

// Řazení dat podle české abecedy
setlocale(LC_COLLATE, 'cs_CZ.UTF-8');
usort($data, function($a, $b) {
    return strcoll(mb_strtolower($a[0], 'UTF-8'), mb_strtolower($b[0], 'UTF-8'));
});
 
// Uložení dat do CSV
$csvFile = 'vitio_cz.csv';
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
