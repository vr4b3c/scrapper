<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// Počet stránek, které chceme projít
$maxPages = 505;

// Inicializace pole pro ukládání dat
$data = [];

echo "<h2>topreality.sk</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

$recordCount = 0;

// Procházení prvních 5 stránek
for ($page = 1; $page <= $maxPages; $page++) {
    $url = "https://www.topreality.sk/realitne-kancelarie/$page/";

    // Stažení HTML obsahu stránky pomocí cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $htmlContent = curl_exec($ch);
    curl_close($ch);

    if (!$htmlContent) {
        echo "Chyba při stahování stránky $page.<br>";
        break;
    }

    // Parsování HTML obsahu
    $html = str_get_html($htmlContent);
    
    // Kontrola, zda stránka obsahuje realitní kanceláře
    if (!$html->find('.agency-listing .row.estate')) {
        echo "Stránka $page neobsahuje žádné kontakty, ukončuji skript.<br>";
        break;
    }

    // Procházení všech záznamů realitních kanceláří
    foreach ($html->find('.agency-listing .row.estate') as $element) {
        $nazev = trim($element->find('h3 a', 0)->plaintext ?? '');

        // Extrakce e-mailu
        $email = '';
        foreach ($element->find('ul.web a') as $link) {
            if (strpos($link->href, 'mailto:') !== false) {
                $email = str_replace('mailto:', '', $link->href);
                break;
            }
        }

        // Extrakce telefonního čísla
        $telefon = trim($element->find('.click_to_call', 0)->plaintext ?? '');

        // Uložení dat do pole
        $data[] = [$nazev, $email, $telefon];
        
        // Výpis do tabulky ihned
        echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
        
        $recordCount++;
    }
}

echo "</table>";

echo "<p>Počet nalezených záznamů: $recordCount</p>";

$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

// Řazení dat podle české abecedy
setlocale(LC_COLLATE, 'cs_CZ.UTF-8');
usort($data, function($a, $b) {
    return strcoll(mb_strtolower($a[0], 'UTF-8'), mb_strtolower($b[0], 'UTF-8'));
});
 

// Uložení dat do CSV až na konci
$csvFile = 'topreality_sk.csv';
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

