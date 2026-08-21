<?php
// ── Moteur RH / Paie — module Ressources humaines ────────────────────
// Construit le 15/08/2026 sur demande explicite (« mettre en place tout le
// système de gestion des enseignants jusqu'à la paie »). Choix confirmés
// par l'utilisateur (AskUserQuestion) : grille salariale par grade/échelon
// (pas un salaire fixe par enseignant), bulletins AVEC retenues (avances +
// absences non soldées), périmètre Fiche + Contrats + Congés + Paie.
//
// Principe repris du module Finances/Dépenses (déjà dans ce projet) : AUCUN
// solde n'est stocké nulle part — tout (solde d'avance, jours d'absence
// déductibles, montant net à payer avant génération) est recalculé à la
// volée à partir des tables-registres (`avance_salaire`+`remboursement_avance`,
// `conge_enseignant`). Une fois un bulletin GÉNÉRÉ, ses montants sont figés
// (colonnes propres à `bulletin_paie`/`ligne_bulletin_paie`) — un changement
// ultérieur de la grille salariale ou une correction de congé ne modifie
// jamais un bulletin déjà émis, seul un nouveau bulletin (nouvelle période)
// est concerné.
require_once __DIR__ . '/fonctions.php';

// ── Grille salariale ────────────────────────────────────────────────

function grille_salariale(): array {
    return db_all("SELECT * FROM grade_enseignant ORDER BY ordre_affichage, libelle_grade");
}

function grade_par_code(string $code_grade): ?array {
    return db_one("SELECT * FROM grade_enseignant WHERE code_grade=?", [$code_grade]);
}

function indemnites_grade(string $code_grade): array {
    return db_all("SELECT * FROM indemnite_grade WHERE code_grade=? ORDER BY libelle_indemnite", [$code_grade]);
}

function total_indemnites_grade(string $code_grade): float {
    return (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM indemnite_grade WHERE code_grade=?", [$code_grade]) ?? 0);
}

// ── Avances sur salaire (registre, jamais de solde stocké) ───────────
// Solde restant d'une avance = montant accordé - somme de tout ce qui en a
// déjà été déduit sur des bulletins (remboursement_avance) — même principe
// que finances_soldes_simules() (pages/finances/versement.php).
function solde_avance(int $id_avance): float {
    $avance = db_one("SELECT montant FROM avance_salaire WHERE id=?", [$id_avance]);
    if (!$avance) return 0.0;
    $rembourse = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM remboursement_avance WHERE id_avance=?", [$id_avance]) ?? 0);
    return max(0.0, (float) $avance['montant'] - $rembourse);
}

// Avances d'un enseignant avec un solde encore dû (> 0), triées de la plus
// ancienne à la plus récente (remboursées en premier, ordre naturel).
function avances_dues_enseignant(int $matricule_ens): array {
    $avances = db_all("SELECT * FROM avance_salaire WHERE matricule_ens=? ORDER BY date_avance, id", [$matricule_ens]);
    $resultat = [];
    foreach ($avances as $a) {
        $solde = solde_avance((int) $a['id']);
        if ($solde > 0.009) $resultat[] = $a + ['solde' => $solde];
    }
    return $resultat;
}

// ── Congés / absences enseignant ──────────────────────────────────────

function libelle_type_conge(string $type): string {
    return match ($type) {
        'Congé payé'              => 'Congé payé',
        'Maladie'                 => 'Maladie',
        'Maternité'                => 'Maternité',
        'Sans solde'               => 'Sans solde',
        'Absence non justifiée'    => 'Absence non justifiée',
        default                    => $type ?: 'Autre',
    };
}

// Nombre de jours calendaires d'un congé qui tombent dans [periode_debut,
// periode_fin] (bornes incluses) — un congé à cheval sur deux mois n'est
// compté que pour sa partie dans le mois demandé.
function jours_conge_dans_periode(string $date_debut, string $date_fin, string $periode_debut, string $periode_fin): int {
    $debut = max($date_debut, $periode_debut);
    $fin   = min($date_fin, $periode_fin);
    if ($debut > $fin) return 0;
    $d1 = new DateTime($debut);
    $d2 = new DateTime($fin);
    return $d1->diff($d2)->days + 1;
}

// Jours d'absence déductibles de la paie pour un enseignant sur une période
// (mois) — uniquement les congés VALIDÉS avec `deduit_paie=1` (Sans solde /
// Absence non justifiée typiquement ; un Congé payé/Maladie/Maternité ne
// coche pas cette case et n'impacte donc jamais le salaire).
function jours_absence_deductibles(int $matricule_ens, string $periode_debut, string $periode_fin): int {
    $conges = db_all(
        "SELECT date_debut, date_fin FROM conge_enseignant
         WHERE matricule_ens=? AND statut='Validé' AND deduit_paie=1
           AND date_fin >= ? AND date_debut <= ?",
        [$matricule_ens, $periode_debut, $periode_fin]
    );
    $total = 0;
    foreach ($conges as $c) {
        $total += jours_conge_dans_periode($c['date_debut'], $c['date_fin'], $periode_debut, $periode_fin);
    }
    return $total;
}

// ── Aperçu / calcul d'un bulletin (pur, aucune écriture) ──────────────
// $avance_a_deduire : montant à prélever ce mois-ci sur les avances dues
// (plafonné à ce qui est réellement dû) — 0 par défaut, saisi manuellement
// lors de la génération (voir pages/paie/index.php). $primes/$retenues_sup
// : lignes ponctuelles ajoutées à la main pour CE bulletin (ex. prime
// exceptionnelle, cotisation) — [['libelle'=>...,'montant'=>...], ...].
function calculer_apercu_bulletin(int $matricule_ens, int $mois, int $annee, float $avance_a_deduire = 0.0, array $primes_sup = [], array $retenues_sup = []): array {
    $ens = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$matricule_ens]);
    $grade = $ens && $ens['id_grade'] ? grade_par_code($ens['id_grade']) : null;

    $salaire_base = $grade ? (float) $grade['salaire_base'] : 0.0;
    $indemnites   = $grade ? indemnites_grade($grade['code_grade']) : [];
    $total_indemnites = array_sum(array_column($indemnites, 'montant'));
    $total_primes = array_sum(array_column($primes_sup, 'montant'));

    $periode_debut = sprintf('%04d-%02d-01', $annee, $mois);
    $periode_fin   = date('Y-m-t', strtotime($periode_debut));
    $jours_absence = jours_absence_deductibles($matricule_ens, $periode_debut, $periode_fin);
    // Valeur du jour = salaire de base / 30 (convention usuelle en l'absence
    // de règle spécifique fournie par l'établissement — ajustable plus tard
    // si une autre règle est communiquée, un seul endroit à changer).
    $valeur_jour = $salaire_base > 0 ? $salaire_base / 30 : 0.0;
    $montant_absence = round($valeur_jour * $jours_absence, 2);

    $dues = avances_dues_enseignant($matricule_ens);
    $total_du_avances = array_sum(array_column($dues, 'solde'));
    $avance_a_deduire = max(0.0, min($avance_a_deduire, $total_du_avances));

    $total_retenues_sup = array_sum(array_column($retenues_sup, 'montant'));

    $brut = $salaire_base + $total_indemnites + $total_primes;
    $total_retenues = $montant_absence + $avance_a_deduire + $total_retenues_sup;
    $net = round($brut - $total_retenues, 2);

    return [
        'enseignant' => $ens, 'grade' => $grade,
        'salaire_base' => $salaire_base, 'indemnites' => $indemnites, 'total_indemnites' => $total_indemnites,
        'primes_sup' => $primes_sup, 'total_primes' => $total_primes,
        'jours_absence' => $jours_absence, 'valeur_jour' => $valeur_jour, 'montant_absence' => $montant_absence,
        'avances_dues' => $dues, 'total_du_avances' => $total_du_avances, 'avance_a_deduire' => $avance_a_deduire,
        'retenues_sup' => $retenues_sup, 'total_retenues_sup' => $total_retenues_sup,
        'brut' => $brut, 'total_retenues' => $total_retenues, 'net_a_payer' => max(0.0, $net),
    ];
}

// ── Génération d'un bulletin (écriture) ───────────────────────────────
// Fige l'aperçu calculé ci-dessus dans bulletin_paie/ligne_bulletin_paie,
// répartit $avance_a_deduire sur les avances dues (plus ancienne d'abord)
// via remboursement_avance. Ré-appelable : si un bulletin existe déjà pour
// (id_periode, matricule_ens), il est supprimé puis recréé (autorisé tant
// que la période est "Brouillon" — voir pages/paie/index.php qui bloque
// cet appel une fois la période "Validée").
// $primes_sup/$retenues_sup : ['libelle'=>..., 'montant'=>..., 'code'=>...
// (optionnel), 'nb'=>... (optionnel), 'base'=>... (optionnel), 'taux_pct'=>...
// (optionnel)] — les 4 clés optionnelles alimentent les colonnes Code/Nb/
// Base/Taux % du bulletin PDF (migration v35, format CNPS) ; laissées vides,
// la ligne s'affiche simplement sans ce détail (rétrocompatible avec les
// bulletins déjà générés avant la v35, qui n'avaient que libellé+montant).
function generer_bulletin(int $id_periode, int $matricule_ens, int $mois, int $annee, float $avance_a_deduire = 0.0, array $primes_sup = [], array $retenues_sup = []): int {
    $apercu = calculer_apercu_bulletin($matricule_ens, $mois, $annee, $avance_a_deduire, $primes_sup, $retenues_sup);

    $existant = db_val("SELECT id FROM bulletin_paie WHERE id_periode=? AND matricule_ens=?", [$id_periode, $matricule_ens]);
    if ($existant) {
        db_exec("DELETE FROM bulletin_paie WHERE id=?", [$existant]); // CASCADE -> lignes + remboursements liés
    }

    $agent = utilisateur_connecte()['id'] ?? null;
    db_exec(
        "INSERT INTO bulletin_paie (id_periode, matricule_ens, code_grade, salaire_base, total_indemnites, total_primes,
             total_retenues, montant_avance_deduite, montant_absence_deduite, jours_absence, brut, net_a_payer, id_utilisateur)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $id_periode, $matricule_ens, $apercu['grade']['code_grade'] ?? null, $apercu['salaire_base'], $apercu['total_indemnites'], $apercu['total_primes'],
            $apercu['total_retenues'], $apercu['avance_a_deduire'], $apercu['montant_absence'], $apercu['jours_absence'], $apercu['brut'], $apercu['net_a_payer'], $agent,
        ]
    );
    $id_bulletin = (int) db_last_id();

    $ordre = 0;
    $ligne = function (string $type, string $libelle, float $montant, ?string $code = null, ?float $nb = null, ?float $base = null, ?float $taux = null) use (&$ordre, $id_bulletin): void {
        if ($type !== 'Sous-total' && abs($montant) < 0.005) return;
        db_exec(
            "INSERT INTO ligne_bulletin_paie (id_bulletin, type_ligne, code_rubrique, libelle, nb, montant, base, taux_pct, ordre_affichage)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$id_bulletin, $type, $code, $libelle, $nb, $montant, $base, $taux, $ordre++]
        );
    };
    $ligne('Gain', 'Salaire de base' . ($apercu['grade'] ? ' (' . $apercu['grade']['libelle_grade'] . ')' : ''), $apercu['salaire_base'], '001', null, $apercu['salaire_base']);
    foreach ($apercu['indemnites'] as $i) { $ligne('Gain', 'Indemnité ' . $i['libelle_indemnite'], (float) $i['montant']); }
    foreach ($apercu['primes_sup'] as $p) {
        $ligne('Gain', $p['libelle'], (float) $p['montant'], $p['code'] ?? null, isset($p['nb']) ? (float) $p['nb'] : null, isset($p['base']) ? (float) $p['base'] : null, isset($p['taux_pct']) ? (float) $p['taux_pct'] : null);
    }
    // Sous-total BRUT — inséré automatiquement (montant déjà connu avec
    // certitude, aucun calcul supplémentaire) entre les gains et les
    // retenues, comme la ligne "099 BRUT" du modèle CNPS fourni. N'affecte
    // aucun total (bulletin_paie.brut existe déjà) : purement un repère
    // visuel dans le tableau unifié du PDF (voir pdf/bulletin_paie.php).
    $ligne('Sous-total', 'BRUT', 0.0, '099', null, $apercu['brut']);
    if ($apercu['jours_absence'] > 0) { $ligne('Retenue', 'Absence non soldée (' . $apercu['jours_absence'] . ' jour(s))', $apercu['montant_absence']); }
    foreach ($apercu['retenues_sup'] as $r) {
        $ligne('Retenue', $r['libelle'], (float) $r['montant'], $r['code'] ?? null, isset($r['nb']) ? (float) $r['nb'] : null, isset($r['base']) ? (float) $r['base'] : null, isset($r['taux_pct']) ? (float) $r['taux_pct'] : null);
    }
    if ($apercu['avance_a_deduire'] > 0.009) { $ligne('Retenue', 'Remboursement avance sur salaire', $apercu['avance_a_deduire']); }
    // Sous-total SALAIRE NET — même principe que BRUT ci-dessus, ligne
    // "150 SALAIRE NET" du modèle, en dernière position.
    $ligne('Sous-total', 'SALAIRE NET', 0.0, '150', null, $apercu['net_a_payer']);

    // Répartition de $avance_a_deduire sur les avances dues, la plus
    // ancienne d'abord (même logique que la cotisation Finances : on épuise
    // dans l'ordre jusqu'à couvrir le montant).
    $restant = $apercu['avance_a_deduire'];
    foreach ($apercu['avances_dues'] as $a) {
        if ($restant <= 0.009) break;
        $part = min($restant, (float) $a['solde']);
        db_exec(
            "INSERT INTO remboursement_avance (id_avance, id_bulletin, montant, date_remboursement) VALUES (?, ?, ?, CURDATE())",
            [$a['id'], $id_bulletin, $part]
        );
        $restant -= $part;
    }

    return $id_bulletin;
}

// ── Marquage "Payé" d'un bulletin (écriture) ──────────────────────────
// Crée une dépense (catégorie "Salaires", cohérence avec solde_caisse() —
// voir fonctions.php) + marque le bulletin payé. Extrait de pages/paie/
// periode.php (16/08/2026, simplification du module Paie) pour être appelé
// aussi bien pour un paiement individuel qu'un paiement groupé (plusieurs
// bulletins sélectionnés, marqués payés en une seule action), sans dupliquer
// cette logique. Sans effet si le bulletin est introuvable ou déjà payé —
// silencieux plutôt qu'une erreur, pour que l'appelant puisse boucler sur une
// sélection sans avoir à filtrer les bulletins déjà payés au préalable.
function marquer_bulletin_paye(int $id_bulletin, ?string $mode, ?string $reference, string $date_pay): array {
    $bulletin = db_one(
        "SELECT b.*, p.libelle AS periode_libelle FROM bulletin_paie b JOIN periode_paie p ON p.id=b.id_periode WHERE b.id=?",
        [$id_bulletin]
    );
    if (!$bulletin || $bulletin['statut'] === 'Payé') {
        return ['ok' => false, 'deja_paye' => (bool) ($bulletin && $bulletin['statut'] === 'Payé'), 'depense_creee' => false];
    }

    $ens          = db_one("SELECT nom_ens, prenom_ens FROM enseignant WHERE matricule_ens=?", [$bulletin['matricule_ens']]);
    $id_categorie = db_val("SELECT id_categorie FROM categorie_depense WHERE libelle='Salaires'");
    $annee_active = get_annee_active()['val_annee'] ?? '';
    $id_depense   = null;
    if ($id_categorie && $annee_active) {
        db_exec(
            "INSERT INTO depense (id_categorie, libelle, montant, date_depense, val_annee, id_utilisateur, beneficiaire, observation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id_categorie, 'Salaire ' . $bulletin['periode_libelle'], (float) $bulletin['net_a_payer'], $date_pay, $annee_active,
                utilisateur_connecte()['id'] ?? null, trim(($ens['nom_ens'] ?? '') . ' ' . ($ens['prenom_ens'] ?? '')),
                'Bulletin ' . numero_bulletin($id_bulletin),
            ]
        );
        $id_depense = db_last_id();
    }
    db_exec(
        "UPDATE bulletin_paie SET statut='Payé', mode_paiement=?, reference_paiement=?, date_paiement=?, id_depense=? WHERE id=?",
        [$mode, $reference, $date_pay, $id_depense, $id_bulletin]
    );
    return ['ok' => true, 'deja_paye' => false, 'depense_creee' => (bool) $id_depense];
}

// ── Libellés / affichage ───────────────────────────────────────────

function libelle_mois(int $mois): string {
    return match ($mois) {
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        default => (string) $mois,
    };
}

// Dérivé de id_bulletin — même principe que finances_numero_recu()/
// finances_numero_bon() (fonctions.php), pas de séquence séparée.
function numero_bulletin(int $id_bulletin): string {
    return 'BP-' . str_pad((string) $id_bulletin, 6, '0', STR_PAD_LEFT);
}

function libelle_type_contrat(string $type): string {
    return match ($type) {
        'Permanent'  => 'Permanent',
        'Vacataire'  => 'Vacataire',
        'CDD'        => 'CDD',
        'Stagiaire'  => 'Stagiaire',
        default      => $type ?: 'Non précisé',
    };
}

// Type de contrat EN COURS d'un enseignant (le plus récent marqué actif=1,
// à défaut le plus récent tout court) — sert de « Sit. adm. » sur le
// bulletin PDF format CNPS (migration v35), voir pdf/bulletin_paie.php.
function contrat_actif_enseignant(int $matricule_ens): ?array {
    return db_one(
        "SELECT * FROM contrat_enseignant WHERE matricule_ens=?
         ORDER BY actif DESC, date_debut DESC LIMIT 1",
        [$matricule_ens]
    );
}

// ── Ancienneté (X Ans et Y Mois) ──────────────────────────────────────
// Affichée sur le bulletin PDF format CNPS (champ "Ancienneté" du modèle
// fourni) — calculée à la volée depuis enseignant.date_recrutement, jamais
// stockée (même principe que le reste du module : rien n'est figé avant
// la génération d'un bulletin, voir en-tête de ce fichier).
function anciennete_libelle(?string $date_recrutement): string {
    if (!$date_recrutement) return '';
    try {
        $debut = new DateTime($date_recrutement);
    } catch (Exception $e) {
        return '';
    }
    $diff = $debut->diff(new DateTime());
    $parties = [];
    if ($diff->y > 0) $parties[] = $diff->y . ' An' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0 || !$parties) $parties[] = $diff->m . ' Mois';
    return implode(' et ', $parties);
}

// ── Montant en toutes lettres (français) ──────────────────────────────
// Ex. 340300 -> "TROIS CENT QUARANTE MILLE TROIS CENTS FRANCS CFA" — champ
// obligatoire du modèle CNPS fourni (BD JAYNITARE/modele/salaire.pdf),
// sous le total "Net à payer". Règles d'accord classiques (pré-réforme
// 1990, celles utilisées sur les documents administratifs camerounais) :
// "vingt"/"cent" prennent un 's' quand multipliés ET non suivis d'un autre
// nombre ; "quatre-vingt" perd son 's' devant "-un" ; "mille" est invariable.
function nombre_en_lettres_fr(int $n): string {
    if ($n === 0) return 'zéro';
    if ($n < 0) return 'moins ' . nombre_en_lettres_fr(-$n);

    $unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
               'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
               'dix-sept', 'dix-huit', 'dix-neuf'];
    $dizaines = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    // Convertit un nombre de 0 à 999.
    $centaine = function (int $n) use ($unites, $dizaines): string {
        $mots = [];
        $c = intdiv($n, 100);
        $reste = $n % 100;
        if ($c > 0) {
            $mots[] = ($c > 1 ? $unites[$c] . ' cent' : 'cent') . ($c > 1 && $reste === 0 ? 's' : '');
        }
        if ($reste > 0) {
            if ($reste < 20) {
                $mots[] = $unites[$reste];
            } else {
                $d = intdiv($reste, 10);
                $u = $reste % 10;
                if ($d === 7 || $d === 9) {
                    // soixante-dix (70-79) / quatre-vingt-dix (90-99) : dizaine
                    // de base (soixante/quatre-vingt) + un nombre de 10 à 19.
                    $mots[] = $dizaines[$d] . '-' . $unites[10 + $u];
                } elseif ($u === 0) {
                    $mots[] = $dizaines[$d] . ($d === 8 ? 's' : ''); // quatre-vingts
                } elseif ($u === 1 && $d !== 8) {
                    $mots[] = $dizaines[$d] . '-et-un'; // vingt-et-un, trente-et-un...
                } else {
                    $mots[] = $dizaines[$d] . '-' . $unites[$u]; // quatre-vingt-un (pas de "-et-")
                }
            }
        }
        return implode(' ', $mots);
    };

    // Découpe en tranches de 3 chiffres (unités, milliers, millions, milliards).
    $tranches = []; $tmp = $n; $i = 0;
    while ($tmp > 0) { $tranches[$i++] = $tmp % 1000; $tmp = intdiv($tmp, 1000); }
    $noms = ['', 'mille', 'million', 'milliard'];

    $parties = [];
    for ($j = count($tranches) - 1; $j >= 0; $j--) {
        $g = $tranches[$j];
        if ($g === 0) continue;
        $mot = $centaine($g);
        if ($j === 1) {
            $parties[] = $g === 1 ? 'mille' : $mot . ' mille'; // "mille", pas "un mille"
        } elseif ($j >= 2) {
            $parties[] = $mot . ' ' . $noms[$j] . ($g > 1 ? 's' : ''); // millions/milliards prennent un 's'
        } else {
            $parties[] = $mot;
        }
    }
    return trim(implode(' ', $parties));
}

function montant_en_lettres(float $montant): string {
    $entier = (int) round($montant);
    if ($entier <= 0) return 'ZÉRO FRANC CFA';
    $unite = $entier > 1 ? 'FRANCS CFA' : 'FRANC CFA';
    return mb_strtoupper(nombre_en_lettres_fr($entier), 'UTF-8') . ' ' . $unite;
}
