<?php
// noyau/partage/gabarits.php — Concepteur de modèles (11/11/2026), primaire et
// secondaire. Un « gabarit » est un document dessiné librement par l'école :
// des éléments (texte riche, logo, photo, QR, rectangle, image) placés au
// millimètre, chacun avec sa police, ses couleurs (mot par mot), sa taille.
// Les textes contiennent des variables ({nom}, {classe}…) remplies à
// l'impression. Rendu FPDF : gab_rendre(). Éditeur :
// secondaire/commun/pages/apparence/concepteur.php.
// Stockage : réglages de l'école « gabarits » (assoc_param), brouillon
// d'aperçu en session, images téléversées dans assets/uploads/gabarits/<CODE>/.
require_once __DIR__ . '/ecole_donnees.php';

/** Types de documents : code => [titre, largeur mm, hauteur mm, variables, sources d'image]. */
function gab_types(): array {
    $ecole = [
        'ecole' => 'Nom de l\'établissement', 'ecole_en' => 'Nom en anglais', 'sigle' => 'Sigle', 'annee' => 'Année scolaire',
        'ville' => 'Ville', 'bp' => 'Boîte postale', 'tel_ecole' => 'Téléphone de l\'école', 'chef' => 'Titre du chef d\'établissement',
        'pays' => 'République du Cameroun', 'devise' => 'Paix – Travail – Patrie', 'region' => 'Région', 'region_en' => 'Région (anglais)',
        'departement' => 'Département', 'division_en' => 'Département (anglais)', 'arrondissement' => 'Arrondissement', 'subdivision_en' => 'Arrondissement (anglais)',
        'email' => 'E-mail de l\'école', 'immatriculation' => 'Immatriculation',
    ];
    $eleve = [
        'nom_complet' => 'Nom et prénom(s)', 'nom' => 'Nom', 'prenom' => 'Prénom(s)', 'classe' => 'Classe',
        'identifiant' => 'NIU (sinon matricule)', 'matricule' => 'Matricule', 'niu' => 'NIU', 'sexe' => 'Sexe',
        'date_naiss' => 'Date de naissance', 'lieu_naiss' => 'Lieu de naissance',
    ];
    return [
        'carte' => ['Carte scolaire', 85.6, 54, $eleve + $ecole, ['logo' => 'Logo de l\'école', 'photo' => 'Photo de l\'élève', 'qr' => 'QR de vérification', 'fichier' => 'Image téléversée']],
        'badge' => ['Badge du personnel', 85.6, 54, ['nom_complet' => 'Nom et prénom(s)', 'nom' => 'Nom', 'prenom' => 'Prénom(s)', 'fonction' => 'Fonction', 'matricule' => 'Matricule', 'tel' => 'Téléphone'] + $ecole, ['logo' => 'Logo de l\'école', 'fichier' => 'Image téléversée']],
        'couverture' => ['Couverture de bulletin', 210, 297, $eleve + $ecole, ['logo' => 'Logo de l\'école', 'photo' => 'Photo de l\'élève', 'fichier' => 'Image téléversée']],
    ] + (gab_bulletin_dispo() ? [
        // Haut du bulletin (en-tête, titre, identité de l'élève) : le tableau des notes suit.
        'bulletin' => ['Haut du bulletin', ed_secondaire() ? 194 : 200, ed_secondaire() ? 58 : 63,
            ['titre' => 'Titre du bulletin', 'titre_en' => 'Titre en anglais', 'periode' => 'Trimestre', 'periode_en' => 'Trimestre (anglais)']
            + $eleve + ['effectif' => 'Effectif de la classe', 'redoublant' => 'Redoublant (Oui / Non)', 'titulaire' => ed_secondaire() ? 'Professeur principal' : 'Titulaire']
            + (ed_secondaire() ? ['parents' => 'Nom et contact des parents'] : []) + $ecole,
            ['logo' => 'Logo de l\'école', 'photo' => 'Photo de l\'élève', 'qr' => 'QR de vérification', 'fichier' => 'Image téléversée']],
    ] : []);
}

/** Bulletins concernés : secondaire général (APC trimestriel) et primaire (trimestriel FR). */
function gab_bulletin_dispo(): bool {
    return true;   // primaire, secondaire général et technique (11/11/2026)
}

const GAB_POLICES = ['helvetica', 'robotoc', 'times', 'courier'];

// ── Modèles de départ (aux couleurs de SIGES) ───────────────────────
function gab_texte_simple(string $id, float $x, float $y, float $w, float $h, array $runs, array $o = []): array {
    return $o + ['id' => $id, 'type' => 'texte', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'runs' => $runs,
        'police' => 'helvetica', 'taille' => 8, 'align' => 'L', 'valign' => 'T', 'interligne' => 1.2, 'ajuster' => 1, 'maj' => 0];
}
function gab_run(string $t, string $c = '#000000', int $b = 0, int $i = 0, int $u = 0): array { return ['t' => $t, 'c' => $c, 'b' => $b, 'i' => $i, 'u' => $u]; }

function gab_depart(string $type): array {
    $M = '#1a2744'; $O = '#c8960a'; $B = '#ffffff';
    if ($type === 'bulletin') return gab_depart_bulletin();
    if ($type === 'couverture') {
        return ['largeur' => 210, 'hauteur' => 297, 'fond' => '#ffffff', 'image_fond' => '', 'elements' => [
            ['id' => 'c1', 'type' => 'rect', 'x' => 8, 'y' => 8, 'w' => 194, 'h' => 281, 'fond' => '', 'bord' => $M, 'ep' => 1],
            ['id' => 'c2', 'type' => 'image', 'x' => 90, 'y' => 18, 'w' => 30, 'h' => 30, 'source' => 'logo', 'ajust' => 'contenir', 'bord' => ''],
            gab_texte_simple('c3', 20, 52, 170, 14, [gab_run('{ecole}', $M, 1)], ['taille' => 18, 'align' => 'C', 'valign' => 'M']),
            ['id' => 'c4', 'type' => 'rect', 'x' => 20, 'y' => 75, 'w' => 170, 'h' => 30, 'fond' => $M, 'bord' => '', 'ep' => 0.3],
            gab_texte_simple('c5', 20, 77, 170, 26, [gab_run("CARNET DE NOTES\n", $B, 1), gab_run('Report booklet', $O, 0, 1)], ['taille' => 22, 'align' => 'C', 'valign' => 'M', 'interligne' => 1.1]),
            gab_texte_simple('c6', 20, 110, 170, 8, [gab_run('Année scolaire {annee}', $O, 1)], ['taille' => 12, 'align' => 'C']),
            ['id' => 'c7', 'type' => 'image', 'x' => 85, 'y' => 128, 'w' => 40, 'h' => 50, 'source' => 'photo', 'ajust' => 'contenir', 'bord' => '#999999'],
            gab_texte_simple('c8', 25, 186, 160, 40, [gab_run('Élève : ', $M, 1), gab_run("{nom_complet}\n", '#000000', 1), gab_run('Classe : ', $M, 1), gab_run("{classe}\n", '#c0392b', 1), gab_run('NIU : ', $M, 1), gab_run('{identifiant}')], ['taille' => 13, 'align' => 'C', 'interligne' => 1.5]),
            ['id' => 'c9', 'type' => 'rect', 'x' => 8, 'y' => 272, 'w' => 194, 'h' => 2.5, 'fond' => $O, 'bord' => '', 'ep' => 0.3],
        ]];
    }
    $badge = $type === 'badge';
    return ['largeur' => 85.6, 'hauteur' => 54, 'fond' => '#ffffff', 'image_fond' => '', 'elements' => array_values(array_filter([
        ['id' => 'e1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 85.6, 'h' => 11.5, 'fond' => $M, 'bord' => '', 'ep' => 0.3],
        ['id' => 'e2', 'type' => 'image', 'x' => 1.5, 'y' => 1, 'w' => 9.5, 'h' => 9.5, 'source' => 'logo', 'ajust' => 'contenir', 'bord' => ''],
        gab_texte_simple('e3', 12, 1.3, 71, 5, [gab_run('{ecole}', $B, 1)], ['taille' => 7.5, 'align' => 'C', 'valign' => 'M']),
        gab_texte_simple('e4', 12, 6.3, 71, 4, [gab_run(($badge ? 'CARTE PROFESSIONNELLE' : 'CARTE D\'IDENTITÉ SCOLAIRE') . ' — {annee}', $B)], ['taille' => 5.5, 'align' => 'C', 'valign' => 'M']),
        ['id' => 'e5', 'type' => 'rect', 'x' => 0, 'y' => 11.5, 'w' => 85.6, 'h' => 0.9, 'fond' => $O, 'bord' => '', 'ep' => 0.3],
        $badge ? null : ['id' => 'e6', 'type' => 'image', 'x' => 3, 'y' => 15, 'w' => 19, 'h' => 24, 'source' => 'photo', 'ajust' => 'contenir', 'bord' => '#9ca3af'],
        $badge ? ['id' => 'e6', 'type' => 'rect', 'x' => 3, 'y' => 15, 'w' => 19, 'h' => 24, 'fond' => '', 'bord' => '#9ca3af', 'ep' => 0.3] : null,
        $badge
            ? gab_texte_simple('e7', 25, 15, 57, 24, [gab_run("{nom_complet}\n", $M, 1), gab_run("{fonction}\n", $O, 1), gab_run('Matricule : ', '#555555'), gab_run("{matricule}\n"), gab_run('Tél. : ', '#555555'), gab_run('{tel}')], ['taille' => 8, 'interligne' => 1.35])
            : gab_texte_simple('e7', 25, 15, 42, 26, [gab_run('Nom : ', $M, 1), gab_run("{nom_complet}\n", '#000000', 1), gab_run('Né(e) le : ', $M, 1), gab_run("{date_naiss} à {lieu_naiss}\n"), gab_run('Classe : ', $M, 1), gab_run("{classe}\n", '#c0392b', 1), gab_run('NIU : ', $M, 1), gab_run('{identifiant}')], ['taille' => 6.5, 'interligne' => 1.35]),
        $badge ? null : ['id' => 'e8', 'type' => 'image', 'x' => 69, 'y' => 30, 'w' => 14, 'h' => 14, 'source' => 'qr', 'ajust' => 'contenir', 'bord' => ''],
        gab_texte_simple('e9', 25, 46, 42, 4, [gab_run('{chef}', '#333333', 0, 1)], ['taille' => 5.5, 'align' => 'R']),
        ['id' => 'e10', 'type' => 'rect', 'x' => 0, 'y' => 52.4, 'w' => 85.6, 'h' => 1.6, 'fond' => $O, 'bord' => '', 'ep' => 0.3],
    ]))];
}

/** Haut du bulletin reproduisant la présentation actuelle (conçu en 194 × 58 mm, mis aux dimensions du type). */
function gab_depart_bulletin(): array {
    $t = gab_types()['bulletin']; $W = $t[1]; $H = $t[2]; $kx = $W / 194; $ky = $H / 58;
    $Bl = '#1a3c6b'; $G = '#e6e6e6'; $N = '#000000';
    $el = []; $n = 0;
    $txt = function (float $x, float $y, float $w, float $h, array $runs, array $o = []) use (&$el, &$n) { $el[] = gab_texte_simple('b' . (++$n), $x, $y, $w, $h, $runs, $o); };
    $rect = function (float $x, float $y, float $w, float $h, string $fond, string $bord, float $ep = 0.2) use (&$el, &$n) { $el[] = ['id' => 'b' . (++$n), 'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fond' => $fond, 'bord' => $bord, 'ep' => $ep]; };
    // En-tête officiel bilingue + logo
    $txt(0, 0, 72, 24, [gab_run("{pays}\n", $N, 1), gab_run("{devise}\n", $N, 0, 1), gab_run("{region}\n{departement}\n{arrondissement}\n"), gab_run("{ecole}\n", $Bl, 1), gab_run('B.P. {bp} – Tél. {tel_ecole}')], ['taille' => 7, 'align' => 'C', 'interligne' => 1.12]);
    $el[] = ['id' => 'b' . (++$n), 'type' => 'image', 'x' => 80, 'y' => 0.5, 'w' => 34, 'h' => 23, 'source' => 'logo', 'ajust' => 'contenir', 'bord' => ''];
    $txt(122, 0, 72, 24, [gab_run("REPUBLIC OF CAMEROON\n", $N, 1), gab_run("Peace – Work – Fatherland\n", $N, 0, 1), gab_run("{region_en}\n{division_en}\n{subdivision_en}\n"), gab_run("{ecole_en}\n", $Bl, 1), gab_run('P.O. Box {bp} – Phone {tel_ecole}')], ['taille' => 7, 'align' => 'C', 'interligne' => 1.12]);
    // Titre et année
    $rect(0, 25, 194, 7, '#dbe4f5', $Bl, 0.3);
    $txt(2, 25, 190, 7, [gab_run('{titre}', $Bl, 1, 1), gab_run(' / {titre_en}', $Bl, 0, 1)], ['taille' => 12, 'align' => 'C', 'valign' => 'M']);
    $txt(0, 32.3, 194, 4.2, [gab_run('Année scolaire : {annee}', $N, 1)], ['taille' => 8.5, 'align' => 'C', 'valign' => 'M']);
    // Photo + grille d'identité
    $el[] = ['id' => 'b' . (++$n), 'type' => 'image', 'x' => 0, 'y' => 37, 'w' => 20, 'h' => 19.7, 'source' => 'photo', 'ajust' => 'etirer', 'bord' => $N];
    $lignes = [
        [['CLASSE / Class', 28], ['{classe}', 46], ['EFFECTIF / Size', 20], ['{effectif}', 12], ['NIU / ID No.', 26], ['{identifiant}', 40]],
        [['NOM ET PRÉNOMS / Name', 28], ['{nom_complet}', 106, 1], ['GENRE / Gender', 16], ['{sexe}', 22]],
        [['NÉ(E) LE / Born on', 28], ['{date_naiss} à {lieu_naiss}', 94], ['REDOUBLANT / Repeater', 26], ['{redoublant}', 24]],
        [[ed_secondaire() ? 'PROF. PRINCIPAL / Class Teacher' : 'TITULAIRE / Class Teacher', 28], ['{titulaire}', ed_secondaire() ? 60 : 144], ...(ed_secondaire() ? [['PARENTS / Parents', 24], ['{parents}', 60]] : [])],
    ];
    foreach ($lignes as $i => $cases) {
        $x = 22; $y = 37 + $i * 5;
        foreach ($cases as $j => $c) {
            $libelle = $j % 2 === 0;
            $rect($x, $y, $c[1], 4.7, $libelle ? $G : '', $N);
            if ($libelle) { [$fr, $en] = explode(' / ', $c[0]) + [1 => '']; $txt($x, $y, $c[1], 4.7, [gab_run($fr . ' ', $N, 1), gab_run($en, '#444444', 0, 1)], ['taille' => 6.5, 'valign' => 'M']); }
            else $txt($x, $y, $c[1], 4.7, [gab_run($c[0], $N, (int) ($c[2] ?? 0), 1)], ['taille' => 8, 'valign' => 'M', 'align' => strlen($c[0]) < 14 ? 'C' : 'L']);
            $x += $c[1];
        }
    }
    foreach ($el as &$e) { $e['x'] = round($e['x'] * $kx, 2); $e['w'] = round($e['w'] * $kx, 2); $e['y'] = round($e['y'] * $ky, 2); $e['h'] = round($e['h'] * $ky, 2); }
    unset($e);
    return ['largeur' => $W, 'hauteur' => $H, 'fond' => '#ffffff', 'image_fond' => '', 'elements' => $el];
}

/** Haut de bulletin enregistré (ou brouillon d'aperçu) — chargé une fois par requête. */
function gab_bulletin_actif(): ?array {
    static $g = false;
    if ($g === false) $g = (!empty($_GET['gab_brouillon']) || gab_existe('bulletin')) ? gab_charger('bulletin', !empty($_GET['gab_brouillon'])) : null;
    return $g;
}
/**
 * Dessine le haut de bulletin du secondaire (général ou technique) à partir
 * des données du bulletin ; $qr = chemin ou fonction qui fabrique le QR.
 * Renvoie true si le QR fait partie du modèle (l'emplacement habituel est alors laissé vide).
 */
function gab_bulletin_secondaire($pdf, array $gab, float $x, float $y, float $w, array $eleve, array $insc, int $nb_inscrits,
                                 string $titre_bull, array $trim_info, string $nom_pp, string $contacts_parents, string $val_annee, array $etab, $qr): bool {
    $en = ([1 => 'First', 2 => 'Second', 3 => 'Third'][(int) ($trim_info['ordre'] ?? 0)] ?? '') . ' Term Report Card';
    $photo = !empty($eleve['photo']) ? photo_sec_fichier_tmp((int) $eleve['id']) : '';
    if (!$photo || !is_file($photo)) $photo = __DIR__ . '/../../assets/img/avatars/' . ((strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png');
    $niu = function_exists('niu_eleve') ? niu_eleve($eleve) : (string) ($eleve['niu'] ?? '');
    $oui = function_exists('bt') ? bt('Oui', 'Yes') : 'Oui'; $non = function_exists('bt') ? bt('Non', 'No') : 'Non';
    $vars = gab_vars_eleve($eleve + ['classe' => $insc['classe'] ?? '']) + gab_vars_ecole($etab, $val_annee) + [
        'titre' => 'BULLETIN SCOLAIRE DU ' . $titre_bull, 'titre_en' => trim($en), 'periode' => $titre_bull, 'periode_en' => trim(str_replace(' Report Card', '', $en)),
        'effectif' => (string) $nb_inscrits, 'identifiant' => $niu, 'niu' => $niu,
        'redoublant' => strtolower($insc['statut'] ?? '') === 'redoublant' ? $oui : $non, 'titulaire' => $nom_pp, 'parents' => $contacts_parents,
    ];
    gab_rendre($pdf, $gab, $x, $y, $w, (float) $gab['hauteur'], $vars, ['logo' => gab_logo($etab), 'photo' => $photo, 'qr' => $qr]);
    return (bool) array_filter($gab['elements'], fn($e) => $e['type'] === 'image' && $e['source'] === 'qr');
}
/** QR de vérification d'un bulletin du secondaire, en fichier temporaire (supprimé en fin de requête). */
function gab_qr_bulletin_secondaire(int $id_eleve, string $vue, int $periode, array $eleve): string {
    require_once __DIR__ . '/../../secondaire/commun/pdf/verif_lib.php';
    require_once __DIR__ . '/../../secondaire/commun/pdf/qrcode.php';
    $f = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
    $img = (new QRCode(bulletin_verif_url($id_eleve, $vue, $periode, id_affichage_eleve($eleve)), ['s' => 'qr-h']))->render_image();
    imagepng($img, $f); imagedestroy($img);
    register_shutdown_function(function () use ($f) { if (is_file($f)) @unlink($f); });
    return $f;
}

// ── Chargement / enregistrement ─────────────────────────────────────
function gab_charger(string $type, bool $brouillon = false): array {
    if ($brouillon && !empty($_SESSION['gab_brouillon'][$type])) return $_SESSION['gab_brouillon'][$type];
    $tous = ed_reglages('gabarits', []);
    return (is_array($tous[$type] ?? null) && !empty($tous[$type]['elements'])) ? $tous[$type] : gab_depart($type);
}
function gab_existe(string $type): bool {
    $tous = ed_reglages('gabarits', []);
    return !empty($tous[$type]['elements']);
}
function gab_enregistrer(string $type, ?array $gab): bool {
    $tous = ed_reglages('gabarits', []);
    if ($gab === null) unset($tous[$type]); else $tous[$type] = $gab;
    return ed_reglages_enregistrer('gabarits', $tous);
}

/** Nettoie un gabarit reçu de l'éditeur (types, bornes, couleurs). */
function gab_nettoyer(string $type, $g): array {
    $t = gab_types()[$type];
    $hex = fn($v) => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '';
    $num = fn($v, $min, $max) => max($min, min($max, round((float) $v, 2)));
    $W = (float) $t[1]; $H = (float) $t[2];
    if ($type === 'bulletin' && ed_secondaire()) $H = max(30, min(150, round((float) ($g['hauteur'] ?? $H), 1)));
    $out = ['largeur' => $W, 'hauteur' => $H, 'fond' => $hex($g['fond'] ?? '') ?: '#ffffff',
        'image_fond' => gab_fichier_valide((string) ($g['image_fond'] ?? '')) ? (string) $g['image_fond'] : '', 'elements' => []];
    foreach (array_slice((array) ($g['elements'] ?? []), 0, 80) as $i => $e) {
        if (!is_array($e) || !in_array($e['type'] ?? '', ['texte', 'image', 'rect'], true)) continue;
        $n = ['id' => preg_replace('/[^a-z0-9_]/i', '', (string) ($e['id'] ?? '')) ?: 'e' . $i, 'type' => $e['type'],
            'x' => $num($e['x'] ?? 0, -$W, 2 * $W), 'y' => $num($e['y'] ?? 0, -$H, 2 * $H), 'w' => $num($e['w'] ?? 10, 0.2, 2 * $W), 'h' => $num($e['h'] ?? 5, 0.2, 2 * $H)];
        if ($e['type'] === 'texte') {
            $runs = [];
            foreach (array_slice((array) ($e['runs'] ?? []), 0, 200) as $r) {
                $txt = mb_substr((string) ($r['t'] ?? ''), 0, 2000);
                if ($txt === '') continue;
                $runs[] = ['t' => $txt, 'c' => $hex($r['c'] ?? '') ?: '#000000', 'b' => !empty($r['b']) ? 1 : 0, 'i' => !empty($r['i']) ? 1 : 0, 'u' => !empty($r['u']) ? 1 : 0];
            }
            $n += ['runs' => $runs, 'police' => in_array($e['police'] ?? '', GAB_POLICES, true) ? $e['police'] : 'helvetica',
                'taille' => $num($e['taille'] ?? 8, 3, 72), 'align' => in_array($e['align'] ?? '', ['L', 'C', 'R'], true) ? $e['align'] : 'L',
                'valign' => in_array($e['valign'] ?? '', ['T', 'M', 'B'], true) ? $e['valign'] : 'T', 'interligne' => $num($e['interligne'] ?? 1.2, 0.8, 3),
                'ajuster' => !empty($e['ajuster']) ? 1 : 0, 'maj' => !empty($e['maj']) ? 1 : 0, 'fond' => $hex($e['fond'] ?? ''), 'bord' => $hex($e['bord'] ?? '')];
        } elseif ($e['type'] === 'image') {
            $src = (string) ($e['source'] ?? 'logo');
            if (!isset($t[4][$src])) $src = 'logo';
            $n += ['source' => $src, 'fichier' => $src === 'fichier' && gab_fichier_valide((string) ($e['fichier'] ?? '')) ? (string) $e['fichier'] : '',
                'ajust' => ($e['ajust'] ?? '') === 'etirer' ? 'etirer' : 'contenir', 'bord' => $hex($e['bord'] ?? '')];
        } else {
            $n += ['fond' => $hex($e['fond'] ?? ''), 'bord' => $hex($e['bord'] ?? ''), 'ep' => $num($e['ep'] ?? 0.3, 0.1, 5)];
        }
        $out['elements'][] = $n;
    }
    return $out;
}

// ── Images téléversées ──────────────────────────────────────────────
function gab_dossier(): string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($e['code'] ?? 'x'));
    return __DIR__ . '/../../assets/uploads/gabarits/' . $code . '/';
}
function gab_fichier_valide(string $f): bool {
    return (bool) preg_match('/^[a-f0-9]{20}\.(png|jpg)$/', $f) && is_file(gab_dossier() . $f);
}
/** Enregistre une image envoyée (PNG / JPEG, 3 Mo max) ; renvoie son nom ou une erreur. */
function gab_televerser(array $fichier): array {
    if (($fichier['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($fichier['tmp_name'] ?? '')) return ['erreur' => 'Envoi impossible.'];
    if ($fichier['size'] > 3 * 1024 * 1024) return ['erreur' => 'Image trop lourde (3 Mo maximum).'];
    $info = @getimagesize($fichier['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2] ?? 0] ?? null;
    if (!$ext) return ['erreur' => 'Formats acceptés : PNG ou JPEG.'];
    $dir = gab_dossier();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['erreur' => 'Dossier d\'images inaccessible.'];
    $nom = substr(sha1_file($fichier['tmp_name']), 0, 20) . '.' . $ext;
    if (!is_file($dir . $nom) && !move_uploaded_file($fichier['tmp_name'], $dir . $nom)) return ['erreur' => 'Enregistrement impossible.'];
    return ['fichier' => $nom, 'url' => APP_URL . '/assets/uploads/gabarits/' . basename(rtrim($dir, '/')) . '/' . $nom];
}

// ── Variables ───────────────────────────────────────────────────────
function gab_vars_ecole(array $etab, string $annee): array {
    $f = fn($k) => trim((string) ($etab[$k] ?? ''));
    return ['ecole' => mb_strtoupper($f('nom_fr')), 'ecole_en' => mb_strtoupper($f('nom_en')), 'sigle' => $f('sigle'), 'annee' => $annee,
        'ville' => $f('ville'), 'bp' => $f('boite_postale'), 'tel_ecole' => $f('telephone'), 'chef' => ed_titre_chef(),
        'pays' => mb_strtoupper($f('pays_etab_fr') ?: 'RÉPUBLIQUE DU CAMEROUN'), 'devise' => 'Paix – Travail – Patrie',
        'region' => mb_strtoupper($f('region_fr')), 'region_en' => mb_strtoupper($f('region_en')), 'departement' => mb_strtoupper($f('departement_fr')),
        'division_en' => mb_strtoupper($f('division_en')), 'arrondissement' => mb_strtoupper($f('arrondissement_fr')), 'subdivision_en' => mb_strtoupper($f('subdivision_en')),
        'email' => $f('email'), 'immatriculation' => $f('immatriculation')];
}
/** Élève (ligne secondaire, primaire ou ed_eleves) → variables. */
function gab_vars_eleve(array $e): array {
    $g = fn(...$k) => (string) (array_values(array_filter(array_map(fn($x) => $e[$x] ?? null, $k), fn($v) => $v !== null && $v !== ''))[0] ?? '');
    $nom = mb_strtoupper($g('nom', 'Nom_elv')); $prenom = $g('prenom', 'Prenom_elv');
    $d = $g('date_naiss', 'Date_naiss_elv');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) $d = $m[3] . '/' . $m[2] . '/' . $m[1];
    $sexe = strtoupper(substr($g('sexe', 'Sexe_elv'), 0, 1));
    $mat = $g('matricule', 'Mat_elv'); $niu = $g('niu');
    return ['nom_complet' => trim($nom . ' ' . $prenom), 'nom' => $nom, 'prenom' => $prenom, 'classe' => $g('classe'),
        'identifiant' => $niu !== '' ? $niu : $mat, 'matricule' => $mat, 'niu' => $niu, 'sexe' => $sexe === 'F' ? 'F' : ($sexe ? 'M' : ''),
        'date_naiss' => $d, 'lieu_naiss' => $g('lieu_naiss', 'Lieu_naiss_elv')];
}
function gab_vars_personnel(array $p): array {
    return ['nom_complet' => ed_nom($p), 'nom' => mb_strtoupper((string) ($p['nom'] ?? '')), 'prenom' => (string) ($p['prenom'] ?? ''),
        'fonction' => mb_strtoupper((string) ($p['fonction'] ?: 'Personnel')), 'matricule' => (string) ($p['matricule'] ?? ''), 'tel' => (string) ($p['tel'] ?? '')];
}
function gab_logo(array $etab): string {
    $p = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
    return $p && is_file($p) ? $p : '';
}

// ── Rendu PDF ───────────────────────────────────────────────────────
function gab_rgb(string $hex): array {
    return preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) ? [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])] : [0, 0, 0];
}
function gab_police($pdf, string $police, string $style, float $taille): void {
    if ($police === 'robotoc') {
        $st = $style === 'IB' ? 'BI' : $style;
        $f = ['' => 'robotoc', 'B' => 'robotocb', 'I' => 'robotoci', 'BI' => 'robotocbi'][$st] ?? 'robotoc';
        $pdf->AddFont('RobotoC', $st, $f . '.php', realpath(__DIR__ . '/../../secondaire/commun/pdf/font') . DIRECTORY_SEPARATOR);
        $pdf->SetFont('RobotoC', $st, $taille);
    } else {
        $pdf->SetFont(in_array($police, GAB_POLICES, true) ? $police : 'helvetica', $style, $taille);
    }
}

/**
 * Dessine le gabarit dans le cadre (X, Y, W, H) : toutes les cotes sont mises
 * à l'échelle du cadre. $sources : 'logo' / 'photo' / 'qr' => chemin ou
 * fonction qui renvoie un chemin (appelée seulement si l'élément existe).
 */
function gab_rendre($pdf, array $gab, float $X, float $Y, float $W, float $H, array $vars, array $sources = []): void {
    // Le gabarit a ses propres polices et couleurs : on neutralise l'apparence globale le temps du dessin.
    $sauve = property_exists($pdf, 'siges_police') ? [$pdf->siges_police, $pdf->siges_echelle, $pdf->siges_couleurs] : null;
    if ($sauve) { $pdf->siges_police = null; $pdf->siges_echelle = 1.0; $pdf->siges_couleurs = []; }
    $sx = $W / max(1, (float) $gab['largeur']); $sy = $H / max(1, (float) $gab['hauteur']); $sf = min($sx, $sy);
    if (!empty($gab['fond']) && strtolower($gab['fond']) !== '#ffffff') { $pdf->SetFillColor(...gab_rgb($gab['fond'])); $pdf->Rect($X, $Y, $W, $H, 'F'); }
    if (!empty($gab['image_fond']) && gab_fichier_valide($gab['image_fond'])) $pdf->Image(gab_dossier() . $gab['image_fond'], $X, $Y, $W, $H);
    $cache = [];
    foreach ($gab['elements'] as $e) {
        $x = $X + $e['x'] * $sx; $y = $Y + $e['y'] * $sy; $w = $e['w'] * $sx; $h = $e['h'] * $sy;
        if ($e['type'] === 'rect') {
            $style = ($e['fond'] ? 'F' : '') . ($e['bord'] ? 'D' : '');
            if ($style === '') continue;
            if ($e['fond']) $pdf->SetFillColor(...gab_rgb($e['fond']));
            if ($e['bord']) { $pdf->SetDrawColor(...gab_rgb($e['bord'])); $pdf->SetLineWidth($e['ep'] * $sf); }
            $pdf->Rect($x, $y, $w, $h, $style === 'FD' ? 'DF' : $style);
        } elseif ($e['type'] === 'image') {
            $src = $e['source'];
            if ($src === 'fichier') $chemin = gab_fichier_valide($e['fichier'] ?? '') ? gab_dossier() . $e['fichier'] : '';
            else {
                if (!array_key_exists($src, $cache)) { $v = $sources[$src] ?? ''; $cache[$src] = is_callable($v) ? (string) $v() : (string) $v; }
                $chemin = $cache[$src];
            }
            if ($chemin && is_file($chemin) && ($dim = @getimagesize($chemin))) {
                if ($e['ajust'] === 'etirer') $pdf->Image($chemin, $x, $y, $w, $h);
                else {
                    $r = min($w / $dim[0], $h / $dim[1]); $iw = $dim[0] * $r; $ih = $dim[1] * $r;
                    $pdf->Image($chemin, $x + ($w - $iw) / 2, $y + ($h - $ih) / 2, $iw, $ih);
                }
            } elseif ($src === 'photo') {
                $pdf->SetDrawColor(180, 180, 180); $pdf->SetLineWidth(0.2); $pdf->Rect($x, $y, $w, $h);
            }
            if ($e['bord']) { $pdf->SetDrawColor(...gab_rgb($e['bord'])); $pdf->SetLineWidth(0.25 * $sf); $pdf->Rect($x, $y, $w, $h); }
        } else {
            gab_texte($pdf, $e, $x, $y, $w, $h, $sf, $vars);
        }
    }
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0); $pdf->SetLineWidth(0.2);
    if ($sauve) [$pdf->siges_police, $pdf->siges_echelle, $pdf->siges_couleurs] = $sauve;
}

/** Remplace les variables {x} d'un texte. */
function gab_remplacer(string $t, array $vars): string {
    return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $t);
}

/** Texte riche : mots colorés / gras / italique / souligné, retour à la ligne automatique, ajustement à la boîte. */
function gab_texte($pdf, array $e, float $x, float $y, float $w, float $h, float $sf, array $vars): void {
    if ($e['fond']) { $pdf->SetFillColor(...gab_rgb($e['fond'])); $pdf->Rect($x, $y, $w, $h, 'F'); }
    if ($e['bord']) { $pdf->SetDrawColor(...gab_rgb($e['bord'])); $pdf->SetLineWidth(0.25 * $sf); $pdf->Rect($x, $y, $w, $h); }
    // Morceaux (mot ou espace ou saut de ligne) avec leur style.
    // Une ligne dont toutes les données sont vides (ex. « Tél. : {tel} » sans
    // téléphone) n'est pas imprimée.
    $brut = explode("\n", implode('', array_column($e['runs'], 't')));
    $masquer = [];
    foreach ($brut as $n => $l) {
        if (preg_match_all('/\{([a-z_]+)\}/', $l, $mm) && !array_filter($mm[1], fn($k) => trim((string) ($vars[$k] ?? '')) !== '')) $masquer[$n] = true;
    }
    $morceaux = []; $ligne = 0;
    foreach ($e['runs'] as $r) {
        foreach (explode("\n", $r['t']) as $j => $partie) {
            if ($j > 0) { if (empty($masquer[$ligne])) $morceaux[] = ['t' => "\n", 'st' => '', 'u' => 0, 'c' => $r['c']]; $ligne++; }
            if (!empty($masquer[$ligne]) || $partie === '') continue;
            $t = gab_remplacer($partie, $vars);
            if ($e['maj']) $t = mb_strtoupper($t);
            foreach (preg_split('/([ \t]+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $m) {
                $morceaux[] = ['t' => $m, 'st' => ($r['b'] ? 'B' : '') . ($r['i'] ? 'I' : ''), 'u' => $r['u'], 'c' => $r['c']];
            }
        }
    }
    while ($morceaux && $morceaux[0]['t'] === "\n") array_shift($morceaux);
    while ($morceaux && end($morceaux)['t'] === "\n") array_pop($morceaux);
    if (!$morceaux) return;
    $pad = 0.4 * $sf;
    $taille = $e['taille'] * $sf;
    $min = max(2.5, $taille * 0.35);
    do {
        $lignes = gab_couper($pdf, $e['police'], $morceaux, $taille, $w - 2 * $pad);
        $lh = $taille * 0.3528 * $e['interligne'];
        $hauteur = count($lignes) * $lh;
        if (!$e['ajuster'] || $hauteur <= $h + 0.01 || $taille <= $min) break;
        $taille -= 0.25;
    } while (true);
    $y0 = $y + ($e['valign'] === 'M' ? ($h - $hauteur) / 2 : ($e['valign'] === 'B' ? $h - $hauteur : 0));
    foreach ($lignes as $n => $ligne) {
        $lw = array_sum(array_column($ligne, 'w'));
        $cx = $x + $pad + ($e['align'] === 'C' ? ($w - 2 * $pad - $lw) / 2 : ($e['align'] === 'R' ? $w - 2 * $pad - $lw : 0));
        $base = $y0 + $n * $lh + ($lh + $taille * 0.3528 * 0.7) / 2;
        foreach ($ligne as $m) {
            if (trim($m['t']) !== '') {
                gab_police($pdf, $e['police'], $m['st'], $taille);
                $pdf->SetTextColor(...gab_rgb($m['c']));
                $pdf->Text($cx, $base, pdf_u($m['t']));
            }
            if ($m['u']) { $pdf->SetDrawColor(...gab_rgb($m['c'])); $pdf->SetLineWidth(max(0.1, $taille * 0.02)); $pdf->Line($cx, $base + $taille * 0.05, $cx + $m['w'], $base + $taille * 0.05); }
            $cx += $m['w'];
        }
    }
}

/** Découpe en lignes (largeur max) ; renvoie [[['t','st','u','c','w'], …], …]. */
function gab_couper($pdf, string $police, array $morceaux, float $taille, float $lmax): array {
    $lignes = [[]]; $l = 0;
    foreach ($morceaux as $m) {
        if ($m['t'] === "\n") { $lignes[] = []; $l = 0; continue; }
        gab_police($pdf, $police, $m['st'], $taille);
        $m['w'] = $pdf->GetStringWidth(pdf_u($m['t']));
        $espace = trim($m['t']) === '';
        if ($espace && !$lignes[count($lignes) - 1]) continue;            // pas d'espace en début de ligne
        if (!$espace && $l + $m['w'] > $lmax && $lignes[count($lignes) - 1]) {
            $der = &$lignes[count($lignes) - 1];                           // retire l'espace de fin
            while ($der && trim(end($der)['t']) === '') { $l -= array_pop($der)['w']; }
            unset($der);
            $lignes[] = []; $l = 0;
        }
        $lignes[count($lignes) - 1][] = $m; $l += $m['w'];
    }
    return $lignes;
}
