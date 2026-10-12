<?php
// bd/lib/compat_php74.php — compatibilité PHP 7.4 → 8.x.
//
// L'application tourne de PHP 7.4 à 8.4. Ce fichier, chargé en tout premier
// par config/config.php, fournit sous PHP 7.4 les fonctions apparues avec PHP 8 et
// utilisées dans le code (sous PHP 8 elles existent déjà : rien n'est redéfini).
//
// Règles pour le code de l'application (vérifiables avec
//   C:\wamp64\bin\php\php7.4.33\php.exe -l fichier.php) :
//   - pas de `match`            → ternaires / switch (comparaison stricte ===)
//   - pas d'arguments nommés    → f($a, $b) dans l'ordre des paramètres
//   - pas de types mixed / never / static / union (int|string), ni ?-> ;
//   - pas de promotion de propriétés dans le constructeur, ni enum / readonly.

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}

// GD : imagefilledpolygon()/imagepolygon() exigent le nombre de points en
// 3ᵉ argument sous PHP 7.4, argument devenu facultatif en 8.0 puis obsolète
// (avertissement) en 8.1. Ces deux fonctions passent la bonne forme selon la version.
function compat_imagefilledpolygon($img, array $points, int $couleur): bool {
    return PHP_VERSION_ID < 80000
        ? imagefilledpolygon($img, $points, intdiv(count($points), 2), $couleur)
        : imagefilledpolygon($img, $points, $couleur);
}

function compat_imagepolygon($img, array $points, int $couleur): bool {
    return PHP_VERSION_ID < 80000
        ? imagepolygon($img, $points, intdiv(count($points), 2), $couleur)
        : imagepolygon($img, $points, $couleur);
}

// Ancien `match` sans branche `default` qui ne trouve aucun cas : même
// comportement que PHP 8 (UnhandledMatchError), une exception est levée.
if (!function_exists('compat_match_echec')) {
    function compat_match_echec($valeur) {
        throw new \RuntimeException('Valeur non prévue : ' . (is_scalar($valeur) ? var_export($valeur, true) : gettype($valeur)));
    }
}

// Filigrane (logo éclairci) en fond d'une feuille Excel : setBackgroundImage()
// n'existe qu'à partir de PhpSpreadsheet 2.x, or la version installée est
// 1.30 (dernière compatible PHP 7.4, voir composer.json). Sans cette
// méthode, l'export se fait sans filigrane au lieu de planter.
// PhpSpreadsheet 1.x : image d'EN-TÊTE de page (&G) — la méthode standard
// d'Excel pour un filigrane, visible en mode « Mise en page » et à
// l'impression / en PDF (pas en mode Normal à l'écran, contrairement au
// fond de feuille de la 2.x).
function excel_filigrane($sheet, string $chemin): void {
    if ($chemin === '' || !is_file($chemin)) return;
    if (method_exists($sheet, 'setBackgroundImage')) {
        $sheet->setBackgroundImage(file_get_contents($chemin));
        return;
    }
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing')) return;
    $dim = @getimagesize($chemin);
    $paysage = $sheet->getPageSetup()->getOrientation() === \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE;
    $largeur = $paysage ? 900 : 640;   // px, ~ largeur utile d'une page A4
    $img = new \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing();
    $img->setName('Filigrane');
    $img->setPath($chemin);
    $img->setWidth($largeur);
    $img->setHeight($dim && $dim[0] > 0 ? (int) round($largeur * $dim[1] / $dim[0]) : (int) round($largeur * 0.7));
    $hf = $sheet->getHeaderFooter();
    $hf->addImage($img, \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter::IMAGE_HEADER_CENTER);
    $hf->setOddHeader('&C&G');
    $hf->setEvenHeader('&C&G');
}
