<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// Base URL a stránkování
$baseUrl = 'https://www.realitycechy.cz/kancelare';
$maxPages = 99;

$data = [];
$previousHtml = null;

echo "<h2>realitycechy.cz</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

// Procházení stránek
for ($page = 1; $page <= $maxPages; $page++) {
    $url = $page == 1 ? $baseUrl : "$baseUrl?vp-page=$page";
    
    // Stažení HTML obsahu stránky
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $htmlContent = curl_exec($ch);
    curl_close($ch);
    
    // Pokud stránka neexistuje nebo vrátí prázdný obsah, ukončíme cyklus
    if (!$htmlContent || strlen(trim($htmlContent)) == 0) {
        echo "<br>Ukončení skriptu: Stránka $url neexistuje nebo je prázdná.<br>";
        break;
    }
    
    // Pokud HTML obsahu odpovídá předchozí stránce, ukončíme cyklus
    if ($previousHtml !== null && $previousHtml === md5($htmlContent)) {
        echo "<br>Ukončení skriptu: Obsah stránky $url je stejný jako předchozí stránka, konec scrapování.<br>";
        break;
    }
    
    $previousHtml = md5($htmlContent);
    $html = str_get_html($htmlContent);
    
    // Procházení všech kanceláří
    $offices = $html->find('.vizitka_kancelar');
    if (empty($offices)) {
        echo "<br>Ukončení skriptu: Nebyly nalezeny žádné záznamy na stránce $url.<br>";
        break;
    }
    
    foreach ($offices as $office) {
        $nazev = trim($office->find('h3', 0)->plaintext ?? '');
        $emailElement = $office->find('.email a', 0);
        $telefonElement = $office->find('.telefon a', 0);
        
        $email = $emailElement ? trim($emailElement->plaintext) : '';
        $telefon = $telefonElement ? trim($telefonElement->plaintext) : '';
        
        $data[] = [$nazev, $email, $telefon];
        
        // Výpis do tabulky ihned
        echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
        
        // Pokud je nalezen požadovaný název, ukončíme skript
        if ($nazev === 'Zprobas s.r.o.') {
            echo "<br>Ukončení skriptu: Nalezen požadovaný záznam '{$nazev}', konec scrapování.<br>";
            break 2; // Ukončíme celý cyklus
        }
    }
}

echo "</table>";

echo "<p>Počet nalezených záznamů: " . count($data) . "</p>";

// Odstranění duplicit
$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

// Řazení dat podle české abecedy
setlocale(LC_COLLATE, 'cs_CZ.UTF-8');
usort($data, function($a, $b) {
    return strcoll(mb_strtolower($a[0], 'UTF-8'), mb_strtolower($b[0], 'UTF-8'));
});

// Uložení dat do CSV
$csvFile = 'realitycechy_cz.csv';
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

