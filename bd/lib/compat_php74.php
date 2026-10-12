<?php
// bd/lib/compat_php74.php — compatibilité PHP 7.4 → 8.x.
//
// L'application tourne de PHP 7.4 à 8.4. Ce fichier, chargé en tout premier
// par config.php, fournit sous PHP 7.4 les fonctions apparues avec PHP 8 et
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
