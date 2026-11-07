<?php
// secondaire/commun/pages/enseignants/_listes_carriere.php — listes de choix
// de la carrière du personnel (07/11/2026) : qualité, diplôme, spécialité,
// poste antérieur, matières enseignées, grade. Au lieu d'une saisie libre
// (« PLEG », « P.L.E.G », « prof lycée »… introuvables à la recherche),
// chaque champ propose une liste ; « Autre… » permet d'ajouter une valeur
// absente, qui apparaît ensuite dans la liste de toute l'école (les valeurs
// déjà enregistrées dans la base sont fusionnées avec la liste de base).

const CARRIERE_LISTES = [
    'qualite' => [
        'Enseignant', 'Enseignant vacataire', 'Enseignant contractuel', 'Enseignant permanent',
        'Professeur principal', 'Chef de département', 'Animateur pédagogique',
        'Conseiller d\'orientation', 'Surveillant', 'Surveillant général', 'Censeur',
        'Intendant', 'Économe', 'Principal', 'Proviseur', 'Directeur', 'Secrétaire', 'Bibliothécaire',
    ],
    'diplome' => [
        'BEPC', 'CAP', 'Probatoire', 'Baccalauréat', 'BT', 'BTS', 'HND', 'DUT',
        'Licence', 'Maîtrise', 'Master', 'DEA', 'Doctorat / PhD',
        'CAPIEMP', 'CAPCEG', 'CAPES', 'CAPET', 'DIPES I', 'DIPES II', 'DIPET I', 'DIPET II', 'DIPCO',
    ],
    'specialite' => [
        'Mathématiques', 'Physique', 'Chimie', 'Physique-Chimie', 'Sciences de la vie et de la terre (SVT)',
        'Biologie', 'Géologie', 'Informatique', 'Français', 'Lettres modernes françaises', 'Lettres bilingues',
        'Anglais', 'Allemand', 'Espagnol', 'Chinois', 'Arabe', 'Italien', 'Langues nationales',
        'Histoire', 'Géographie', 'Histoire-Géographie', 'Philosophie', 'Éducation physique et sportive (EPS)',
        'Économie', 'Comptabilité', 'Commerce', 'Gestion', 'Droit', 'Secrétariat',
        'Électrotechnique', 'Électronique', 'Génie civil', 'Génie mécanique', 'Fabrication mécanique',
        'Menuiserie / Ébénisterie', 'Maçonnerie', 'Industrie de l\'habillement',
        'Économie sociale et familiale (ESF)', 'Orientation-conseil', 'Travail manuel',
    ],
    'poste_anterieur' => [
        'Aucun (premier poste)', 'Étudiant (école normale)', 'Enseignant', 'Professeur principal',
        'Chef de département', 'Animateur pédagogique', 'Conseiller d\'orientation', 'Surveillant',
        'Surveillant général', 'Censeur', 'Intendant', 'Économe', 'Principal', 'Proviseur', 'Directeur',
        'Inspecteur pédagogique',
    ],
];

/** Clé de comparaison : casse et espaces ignorés. */
function carriere_cle(string $v): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)), 'UTF-8');
}

/** Ajoute $valeurs à $liste sans doublon (casse ignorée), dans l'ordre. */
function carriere_fusion(array $liste, array $valeurs): array {
    $vu = [];
    foreach ($liste as $v) $vu[carriere_cle($v)] = true;
    foreach ($valeurs as $v) {
        $v = preg_replace('/\s+/u', ' ', trim((string) $v));
        if ($v === '' || isset($vu[carriere_cle($v)])) continue;
        $vu[carriere_cle($v)] = true;
        $liste[] = $v;
    }
    return $liste;
}

/** Options d'un champ simple : liste de base + valeurs déjà saisies à l'école. */
function carriere_options(string $champ): array {
    $base = CARRIERE_LISTES[$champ] ?? [];
    $deja = [];
    try {
        $deja = array_column(db_all("SELECT DISTINCT `$champ` AS v FROM enseignant WHERE `$champ` IS NOT NULL AND `$champ`<>'' ORDER BY v"), 'v');
    } catch (\Throwable $e) {}
    return carriere_fusion($base, $deja);
}

/** Séparateur des matières multiples dans enseignant.matiere_enseignee. */
const CARRIERE_SEP_MATIERES = ', ';

function carriere_matieres_de(?string $valeur): array {
    $out = [];
    foreach (preg_split('/\s*[,;]\s*/u', (string) $valeur) as $m) if (trim($m) !== '') $out[] = trim($m);
    return $out;
}

/**
 * Matières proposées : celles de l'école (table matiere) + celles déjà saisies
 * sur les fiches. Selon la langue d'enseignement (07/11/2026) : école
 * francophone → matières de la section française seulement, anglophone →
 * section anglaise, bilingue → toutes. Une matière sans section (technique)
 * est toujours proposée ; seules les matières actives sont listées.
 */
function carriere_options_matieres(): array {
    $langue = function_exists('langue_enseignement_courante') ? langue_enseignement_courante() : 'bilingue';
    $mat = [];
    try {
        foreach (db_all("SELECT TRIM(libelle) AS v, libelle_section AS s, actif FROM matiere WHERE libelle IS NOT NULL AND TRIM(libelle)<>'' ORDER BY v") as $r) {
            if (isset($r['actif']) && (int) $r['actif'] === 0) continue;
            $s = trim((string) $r['s']);
            if ($langue !== 'bilingue' && $s !== '') {
                $an = (bool) preg_match('/^(an|en)/i', $s);
                if ($an !== ($langue === 'anglophone')) continue;
            }
            $mat[] = $r['v'];
        }
    } catch (\Throwable $e) {
        // Base sans libelle_section / actif : toutes les matières.
        try { $mat = array_column(db_all("SELECT DISTINCT TRIM(libelle) AS v FROM matiere WHERE libelle IS NOT NULL AND TRIM(libelle)<>'' ORDER BY v"), 'v'); } catch (\Throwable $e2) {}
    }
    $deja = [];
    try {
        foreach (db_all("SELECT matiere_enseignee AS v FROM enseignant WHERE matiere_enseignee IS NOT NULL AND matiere_enseignee<>''") as $r) {
            foreach (carriere_matieres_de($r['v']) as $m) {
                // Restes d'anciennes saisies libres (« (6e », « 5e) »…) écartés.
                if (mb_strlen($m) >= 2 && !preg_match('/[()]|^\d/u', $m)) $deja[] = $m;
            }
        }
    } catch (\Throwable $e) {}
    $l = carriere_fusion([], array_merge($mat, $deja));
    usort($l, fn($a, $b) => strcoll(carriere_cle($a), carriere_cle($b)));
    return $l;
}

/**
 * Code d'un grade ajouté par « Autre… » : le texte lui-même s'il est court
 * et d'un seul mot (« PLEG »), sinon le sigle des mots significatifs
 * (« Professeur certifié technique » → « PCT »). Sans accents, 20 car. max.
 */
function carriere_code_grade(string $libelle): string {
    // Accents retirés à la main (iconv//TRANSLIT n'est pas fiable sous Windows).
    $t = strtr(mb_strtoupper($libelle, 'UTF-8'), ['À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Î'=>'I','Ï'=>'I','Ô'=>'O','Ö'=>'O','Ù'=>'U','Û'=>'U','Ü'=>'U','Ÿ'=>'Y','Œ'=>'OE','Æ'=>'AE']);
    $t = preg_replace('/[^A-Z0-9 ]+/', ' ', $t);
    $mots = preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY);
    if (count($mots) === 1) return substr($mots[0], 0, 20);
    $vides = ['DE', 'DES', 'DU', 'D', 'LA', 'LE', 'LES', 'L', 'ET', 'EN', 'AU', 'AUX', 'A', 'OF', 'THE', 'AND'];
    $sigle = '';
    foreach ($mots as $m) {
        if (ctype_digit($m)) $sigle .= $m;
        elseif (!in_array($m, $vides, true)) $sigle .= $m[0];
    }
    return substr($sigle !== '' ? $sigle : implode('', $mots), 0, 20) ?: 'GRADE';
}

/** Valeur postée d'un champ à liste (choix ou « Autre… »). */
function carriere_post(string $nom): ?string {
    $v = (string) ($_POST[$nom] ?? '');
    if ($v === '__autre__') $v = (string) ($_POST[$nom . '_autre'] ?? '');
    $v = preg_replace('/\s+/u', ' ', trim($v));
    return $v === '' ? null : mb_substr($v, 0, 120);
}

/** Matières postées (cases cochées + « autres » séparées par des virgules), 255 caractères au plus. */
function carriere_post_matieres(string $nom = 'matiere_enseignee'): ?string {
    $l = [];
    foreach ((array) ($_POST[$nom] ?? []) as $m) $l[] = (string) $m;
    foreach (carriere_matieres_de((string) ($_POST[$nom . '_autre'] ?? '')) as $m) $l[] = $m;
    $l = carriere_fusion([], $l);
    $v = implode(CARRIERE_SEP_MATIERES, $l);
    return $v === '' ? null : mb_substr($v, 0, 255);
}

/**
 * Liste déroulante + « Autre… » (champ texte affiché à la demande).
 * Une valeur actuelle absente de la liste est ajoutée et sélectionnée.
 */
function carriere_select(string $nom, ?string $valeur, array $options, string $classe = 'form-select form-select-sm'): string {
    $valeur = trim((string) $valeur);
    if ($valeur !== '') $options = carriere_fusion($options, [$valeur]);
    $cle = carriere_cle($valeur);
    $id = 'car_' . preg_replace('/\W/', '_', $nom);
    $h = '<select name="' . h($nom) . '" id="' . $id . '" class="' . h($classe) . ' js-carriere-select">';
    $h .= '<option value="">— Choisir —</option>';
    foreach ($options as $o) {
        $h .= '<option value="' . h($o) . '"' . ($valeur !== '' && carriere_cle($o) === $cle ? ' selected' : '') . '>' . h($o) . '</option>';
    }
    $h .= '<option value="__autre__">➕ Autre (ajouter à la liste)…</option></select>';
    $h .= '<input type="text" name="' . h($nom) . '_autre" maxlength="120" class="form-control form-control-sm mt-1 js-carriere-autre" style="display:none" placeholder="Saisir la nouvelle valeur">';
    return $h;
}

/** Cases à cocher des matières (plusieurs possibles) + filtre + « autres matières ». */
function carriere_matieres(string $nom, ?string $valeur, array $options): string {
    $choisies = carriere_matieres_de($valeur);
    $options = carriere_fusion($options, $choisies);
    $cles = array_flip(array_map('carriere_cle', $choisies));
    $h = '<div class="js-carriere-matieres border rounded p-2" style="background:#fff">';
    $h .= '<div class="d-flex gap-2 mb-1 align-items-center"><input type="search" class="form-control form-control-sm js-filtre" placeholder="Filtrer les matières…" style="max-width:260px">'
        . '<span class="text-muted js-compte" style="font-size:.75rem"></span></div>';
    $h .= '<div style="max-height:170px;overflow:auto;columns:220px;column-gap:14px;font-size:.82rem">';
    foreach ($options as $i => $o) {
        $idc = 'mat_' . preg_replace('/\W/', '_', $nom) . '_' . $i;
        $h .= '<div class="form-check js-item" style="break-inside:avoid"><input class="form-check-input" type="checkbox" name="' . h($nom) . '[]" value="' . h($o) . '" id="' . $idc . '"'
            . (isset($cles[carriere_cle($o)]) ? ' checked' : '') . '><label class="form-check-label" for="' . $idc . '">' . h($o) . '</label></div>';
    }
    $h .= '</div>';
    $h .= '<input type="text" name="' . h($nom) . '_autre" class="form-control form-control-sm mt-1" maxlength="200" placeholder="➕ Autre(s) matière(s) absente(s) de la liste, séparées par des virgules">';
    $h .= '</div>';
    return $h;
}

/** Script des listes (à inclure une fois, après les champs). */
function carriere_script(): string {
    return <<<'JS'
<script>
(function () {
  document.querySelectorAll('.js-carriere-select').forEach(function (s) {
    var autre = s.nextElementSibling;
    s.addEventListener('change', function () {
      var o = s.value === '__autre__';
      autre.style.display = o ? 'block' : 'none';
      autre.required = o;
      if (o) autre.focus();
    });
  });
  document.querySelectorAll('.js-carriere-matieres').forEach(function (b) {
    var f = b.querySelector('.js-filtre'), c = b.querySelector('.js-compte');
    function compte() { var n = b.querySelectorAll('input[type=checkbox]:checked').length; c.textContent = n ? n + ' cochée(s)' : ''; }
    f.addEventListener('input', function () {
      var q = f.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      b.querySelectorAll('.js-item').forEach(function (it) {
        var t = it.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        it.style.display = t.indexOf(q) >= 0 ? '' : 'none';
      });
    });
    b.addEventListener('change', compte); compte();
  });
})();
</script>
JS;
}
