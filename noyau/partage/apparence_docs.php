<?php
// noyau/partage/apparence_docs.php — Apparence des documents (11/11/2026),
// primaire et secondaire : police, taille du texte et couleur principale
// choisies par l'école pour ses cartes scolaires, ses bulletins et ses autres
// documents PDF, plus les choix par défaut des cartes (modèle, format,
// recto-verso). Réglages par école (assoc_param « apparence_<CODE> »).
// Application : app_appliquer($pdf, 'cartes'|'bulletins'|'documents') juste
// après new FPDF — le crochet est dans les deux fpdf.php (siges_*).
require_once __DIR__ . '/ecole_donnees.php';

/** Polices embarquées : code => [libellé, pile CSS pour l'aperçu, largeur relative]. */
const APP_POLICES = [
    'helvetica' => ['Arial / Helvetica (standard)', 'Arial, Helvetica, sans-serif'],
    'robotoc'   => ['Roboto condensée (plus de texte par ligne)', '"Roboto Condensed", "Arial Narrow", "Segoe UI", sans-serif'],
    'times'     => ['Times (classique, avec empattements)', '"Times New Roman", Times, serif'],
    'courier'   => ['Courier (machine à écrire)', '"Courier New", Courier, monospace'],
];

/** Documents réglables : code => [titre, icône, polices permises, réglages par défaut]. */
function app_documents(): array {
    $sec = ed_secondaire();
    return [
        'cartes' => ['Cartes scolaires', 'credit-card-2-front', ['helvetica', 'robotoc', 'times', 'courier'],
            ['police' => 'helvetica', 'echelle' => 100, 'couleur' => '#1e4fd8', 'modele' => 1, 'format' => 0, 'verso' => 0, 'signature' => 0]],
        'bulletins' => ['Bulletins de notes', 'journal-text', ['robotoc', 'helvetica', 'times'],
            ['police' => ($sec && !est_technique()) ? 'robotoc' : 'helvetica', 'couleurs' => []]],
        'documents' => ['Autres documents', 'files', ['helvetica', 'robotoc', 'times', 'courier'],
            ['police' => 'helvetica', 'echelle' => 100, 'couleur' => '#1a2744', 'accent' => '#c8960a']],
    ];
}

/**
 * Bulletins : couleurs des cellules, des textes et des bordures, en plus des
 * rôles déjà en base (table pdf_couleur, « Couleurs bulletin »).
 * code => [libellé, [canal:couleur d'origine, …], couleur par défaut].
 * Canaux : T = texte, F = fond de case, D = bordure / trait.
 */
function app_bulletin_roles(): array {
    if (!ed_secondaire()) {
        return [
            'texte'   => ['Texte du bulletin', ['T:0,0,0'], '#000000'],
            'bordure' => ['Bordures des cases et cadres', ['D:0,0,0'], '#000000'],
        ];
    }
    return [
        'texte'         => ['Texte du bulletin', ['T:0,0,0'], '#000000'],
        'texte_titre'   => ['Titres (bulletin, école)', ['T:26,60,107'], '#1a3c6b'],
        'texte_clair'   => ['Texte sur les bandeaux foncés', ['T:255,255,255'], '#ffffff'],
        'texte_echec'   => ['Notes insuffisantes (rouge)', ['T:180,0,0'], '#b40000'],
        'texte_reussite'=> ['Bonnes notes (vert)', ['T:0,100,0', 'T:0,130,0'], '#006400'],
        'texte_bleu'    => ['Texte bleu (mentions, appréciations)', ['T:0,0,180'], '#0000b4'],
        'case_libelle'  => ['Cases des libellés (CLASSE, NOM…)', ['F:230,230,230'], '#e6e6e6'],
        'zebrage'       => ['Lignes alternées du tableau', ['F:238,242,248'], '#eef2f8'],
        'bordure'       => ['Bordures des cases et cadres', ['D:0,0,0'], '#000000'],
    ];
}

/** Rôles de la table pdf_couleur de l'école (libellé + couleur actuelle). */
function app_bulletin_roles_base(): array {
    try {
        $out = [];
        foreach (db_all("SELECT cle, libelle, r, g, b FROM pdf_couleur ORDER BY libelle") as $c) {
            if (!ed_secondaire() && preg_match('/arabe/i', (string) $c['libelle'])) continue;
            $out[$c['cle']] = [(string) $c['libelle'], sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b'])];
        }
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Couleurs « de modèle » remplacées par la couleur de l'école. */
const APP_COULEURS_SOURCE = [
    'cartes'    => ['couleur' => ['30,79,216', '20,120,110']],
    'documents' => ['couleur' => ['26,39,68'], 'accent' => ['200,150,10']],
];

function app_hex_rgb(string $hex): ?array {
    return preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', trim($hex), $m) ? [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])] : null;
}

/** Réglages complets d'un document (défauts + valeurs de l'école). */
function app_reglages(string $doc): array {
    $docs = app_documents();
    if (!isset($docs[$doc])) return [];
    $tout = ed_reglages('apparence', []);
    $v = is_array($tout[$doc] ?? null) ? $tout[$doc] : [];
    $r = $v + $docs[$doc][3];
    if (!in_array($r['police'], $docs[$doc][2], true)) $r['police'] = $docs[$doc][3]['police'];
    if (isset($r['echelle'])) $r['echelle'] = max(90, min(115, (int) $r['echelle']));
    return $r;
}

/** Enregistre les réglages d'un document (ou de tous). */
function app_enregistrer(string $doc, array $valeurs): bool {
    $tout = ed_reglages('apparence', []);
    $tout[$doc] = $valeurs;
    return ed_reglages_enregistrer('apparence', $tout);
}

/** Applique l'apparence de l'école à un PDF (sans effet si FPDF n'a pas le crochet). */
function app_appliquer($pdf, string $doc, ?array $r = null): void {
    if (!is_object($pdf) || !property_exists($pdf, 'siges_police')) return;
    $r = $r ?? app_reglages($doc);
    if (!$r) return;
    $pdf->siges_police_dir = realpath(__DIR__ . '/../../secondaire/commun/pdf/font') . DIRECTORY_SEPARATOR;
    // Bulletins : Roboto condensée est déjà la police par défaut du général.
    // Bulletins : la police d'origine (Roboto condensée au général, Arial ailleurs) ne demande aucun changement.
    $pdf->siges_police = ($doc === 'bulletins' && $r['police'] === app_documents()['bulletins'][3]['police']) ? null : $r['police'];
    if (isset($r['echelle'])) $pdf->siges_echelle = $r['echelle'] / 100;
    $map = [];
    foreach (APP_COULEURS_SOURCE[$doc] ?? [] as $cle => $sources) {
        $rgb = app_hex_rgb((string) ($r[$cle] ?? ''));
        if ($rgb) foreach ($sources as $s) if ($s !== implode(',', $rgb)) $map[$s] = $rgb;
    }
    if ($doc === 'bulletins') {
        foreach (app_bulletin_roles() as $code => [$lib, $sources, $defaut]) {
            $rgb = app_hex_rgb((string) ($r['couleurs'][$code] ?? ''));
            if (!$rgb || strtolower((string) $r['couleurs'][$code]) === $defaut) continue;
            foreach ($sources as $s) $map[$s] = $rgb;
        }
    }
    $pdf->siges_couleurs = $map;
    if ($doc === 'bulletins' && $map) { $pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0); }   // état initial (noir) déjà converti
}

/** Réglages passés en GET pour l'aperçu en direct (prioritaires, non enregistrés). */
function app_reglages_apercu(string $doc): ?array {
    if (empty($_GET['app_apercu'])) return null;
    $r = app_reglages($doc);
    foreach (['police', 'couleur', 'accent'] as $k) if (isset($_GET['app_' . $k])) $r[$k] = (string) $_GET['app_' . $k];
    if (isset($_GET['app_echelle'])) $r['echelle'] = max(90, min(115, (int) $_GET['app_echelle']));
    if ($doc === 'bulletins') {
        foreach (array_keys(app_bulletin_roles()) as $code) if (isset($_GET['app_c_' . $code]) && app_hex_rgb((string) $_GET['app_c_' . $code])) $r['couleurs'][$code] = strtolower((string) $_GET['app_c_' . $code]);
        foreach (array_keys(app_bulletin_roles_base()) as $cle) {
            $rgb = app_hex_rgb((string) ($_GET['app_pdf_' . $cle] ?? ''));
            if ($rgb) $GLOBALS['APP_PDF_COULEURS'][$cle] = $rgb;
        }
    }
    $docs = app_documents();
    if (!in_array($r['police'], $docs[$doc][2], true)) $r['police'] = $docs[$doc][3]['police'];
    return $r;
}

/** Raccourci pour les générateurs : réglages enregistrés, ou ceux de l'aperçu. */
function app_appliquer_doc($pdf, string $doc): void {
    app_appliquer($pdf, $doc, app_reglages_apercu($doc));
}
