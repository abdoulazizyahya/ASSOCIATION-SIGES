<?php
// bd/lib/lieux_normalisation.php — Normalisation & rapprochement de libellés
// géographiques (région/département/arrondissement), réutilisé par :
//   - bd/sync_arrondissements.php (import CSV/Excel dans la table de référence)
//   - toute migration future de données existantes (ancien texte libre →
//     id_arrondissement, cf. spec « rapprochement en 2 passes »).
//
// Volontairement un simple fichier de fonctions (pas de classe) — cohérent
// avec le style procédural du reste du projet (connexion.php, fonctions.php).

// ── Passe 1 : normalisation exacte ──────────────────────────────────
// iconv('UTF-8','ASCII//TRANSLIT//IGNORE', …) est peu fiable selon la
// plateforme/le serveur (échoue silencieusement sur certains caractères
// accentués selon la forme Unicode source) — on utilise donc une table de
// correspondance manuelle explicite, pas iconv.
const LIEU_TABLE_ACCENTS = [
    'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
    'À'=>'a','Á'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','Å'=>'a',
    'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e',
    'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i',
    'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ò'=>'o','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
    'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','Ù'=>'u','Ú'=>'u','Û'=>'u','Ü'=>'u',
    'ç'=>'c','Ç'=>'c',
    'ñ'=>'n','Ñ'=>'n',
    'ý'=>'y','ÿ'=>'y','Ý'=>'y','Ÿ'=>'y',
    'œ'=>'oe','Œ'=>'oe','æ'=>'ae','Æ'=>'ae',
    "'"=>' ', '’'=>' ', '`'=>' ',
];

// Convertit un nombre romain (ex. "VII") en entier — utilisé en fin de
// libellé uniquement (ex. "Yaoundé Ier", "Douala Vème").
function lieu_romain_vers_entier(string $romain): ?int {
    $valeurs = ['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];
    $romain  = strtoupper($romain);
    if ($romain === '' || !preg_match('/^[IVXLCDM]+$/', $romain)) return null;
    $total = 0; $precedent = 0;
    for ($i = strlen($romain) - 1; $i >= 0; $i--) {
        $val = $valeurs[$romain[$i]];
        $total += ($val < $precedent) ? -$val : $val;
        $precedent = $val;
    }
    return $total > 0 ? $total : null;
}

// Repère un ordinal en fin de chaîne — romain (« Ier », « IIème », « Vème »,
// « VII » seul...) OU déjà en chiffres arabes avec suffixe ordinal français
// (« 1ER », « 2ème », **« 2e »** — les trois rencontrés tels quels dans les
// saisies libres réelles, ex. eleve.arrondissement_elv = « NGAOUNDERE 1ER »
// / « NGAOUNDERE 2e »)  — et le ramène au chiffre arabe seul, sans suffixe
// (« Yaoundé Ier »/« Yaoundé 1ER »/« Yaoundé 2e » → « Yaoundé 1 »/« ... 2 »),
// pour que toutes les formes se comparent identiques. Sans le suffixe court
// « e » (abréviation française usuelle de « -ième »), « Ngaoundéré 2e »
// restait tel quel et devenait ambigu par distance de Levenshtein entre
// Ngaoundéré 1/2/3 — corrigé après l'avoir constaté sur des données réelles.
function lieu_convertir_ordinaux_romains(string $s): string {
    return preg_replace_callback(
        '/\b(\d+|[IVXLCDM]+)\s*(er|ère|eme|ème|e)?\.?\s*$/ui',
        function ($m) {
            if (ctype_digit($m[1])) return $m[1];
            $val = lieu_romain_vers_entier($m[1]);
            return $val !== null ? (string) $val : $m[0];
        },
        $s
    );
}

// Normalisation complète : minuscule, accents retirés (table manuelle),
// ordinaux romains → arabes, ponctuation/espaces réduits — utilisée
// uniquement pour la COMPARAISON (jamais pour l'affichage ni le stockage,
// qui gardent le libellé original tel que saisi/importé).
function lieu_normaliser(string $s): string {
    $s = trim($s);
    $s = lieu_convertir_ordinaux_romains($s);
    $s = strtr($s, LIEU_TABLE_ACCENTS);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return $s;
}

// ── Passe 2 : correspondance floue (coquilles) ──────────────────────
// $candidats : liste de ['id'=>.., 'nom'=>..]
// Retourne un rapport détaillé plutôt qu'une simple valeur, pour permettre
// un affichage clair des cas ambigus/non résolus dans le rapport du script
// de synchronisation (jamais de choix automatique silencieux en cas de doute).
//
// Retour : [
//   'statut'    => 'exact' | 'flou' | 'ambigu' | 'aucun',
//   'candidat'  => ['id'=>.., 'nom'=>..] | null,
//   'distance'  => int|null,               // seulement si 'flou'
//   'concurrents' => [['id'=>,'nom'=>,'distance'=>], ...],  // seulement si 'ambigu'
// ]
function lieu_trouver_correspondance(string $texte, array $candidats, int $seuil = 2): array {
    $texte_norm = lieu_normaliser($texte);
    if ($texte_norm === '') {
        return ['statut' => 'aucun', 'candidat' => null, 'distance' => null, 'concurrents' => []];
    }

    // Passe 1 — exacte après normalisation
    foreach ($candidats as $c) {
        if (lieu_normaliser($c['nom']) === $texte_norm) {
            return ['statut' => 'exact', 'candidat' => $c, 'distance' => 0, 'concurrents' => []];
        }
    }

    // Passe 2 — floue : uniquement pour des noms normalisés d'au moins 4
    // caractères (évite les faux positifs sur des libellés très courts),
    // et uniquement si UN SEUL candidat est sous le seuil (sinon ambigu —
    // signalé, jamais choisi au hasard).
    if (mb_strlen($texte_norm, 'UTF-8') < 4) {
        return ['statut' => 'aucun', 'candidat' => null, 'distance' => null, 'concurrents' => []];
    }
    $sous_seuil = [];
    foreach ($candidats as $c) {
        $nom_norm = lieu_normaliser($c['nom']);
        if (mb_strlen($nom_norm, 'UTF-8') < 4) continue;
        // Piège réel rencontré à l'import (Cameroun) : les grandes villes sont
        // découpées en arrondissements numérotés (« Yaoundé 1 »..« Yaoundé 7 »,
        // « Douala 1 »..« Douala 6 », etc.) — deux arrondissements DISTINCTS
        // dont les libellés normalisés ne diffèrent que par ce numéro final
        // sont à distance de Levenshtein 1 (largement sous le seuil par
        // défaut de 2), ce qui les faisait fusionner à tort lors de l'import
        // (« Yaoundé 2 » jugé "coquille" de « Yaoundé 1 » déjà en base, donc
        // jamais créé). Si les deux libellés se terminent par un nombre et
        // que ces nombres diffèrent, ce n'est jamais une coquille — on exclut
        // ce candidat de la passe floue, quelle que soit la distance.
        if (preg_match('/(\d+)$/', $texte_norm, $mt) && preg_match('/(\d+)$/', $nom_norm, $mc) && $mt[1] !== $mc[1]) {
            continue;
        }
        $d = levenshtein($texte_norm, $nom_norm);
        if ($d <= $seuil) $sous_seuil[] = ['id' => $c['id'], 'nom' => $c['nom'], 'distance' => $d];
    }
    if (count($sous_seuil) === 1) {
        return ['statut' => 'flou', 'candidat' => ['id' => $sous_seuil[0]['id'], 'nom' => $sous_seuil[0]['nom']],
                 'distance' => $sous_seuil[0]['distance'], 'concurrents' => []];
    }
    if (count($sous_seuil) > 1) {
        return ['statut' => 'ambigu', 'candidat' => null, 'distance' => null, 'concurrents' => $sous_seuil];
    }
    return ['statut' => 'aucun', 'candidat' => null, 'distance' => null, 'concurrents' => []];
}
