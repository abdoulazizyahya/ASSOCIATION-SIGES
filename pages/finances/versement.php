<?php
// pages/finances/versement.php — Finances > Versement : enregistrement des
// paiements de frais scolaires, modèle du VRAI jaynitaare (legacy
// php/save_payment_inscription.php), PAS le modèle ABZ_MBE qui traîne mort
// dans pages/paiements/* (obligation_frais/operateur_paiement/numero_recu —
// rien de tout ça n'existe ici).
//
// Modèle réel (`obligation` + `paiement_frais`, déjà présents et peuplés —
// 16 obligations, 518 versements historiques) : un niveau peut avoir
// PLUSIEURS obligations (INSCRIPTION/SCOLARITÉ/APEE/CADEAU...) ; le total dû
// par un élève est la somme de toutes les obligations de son niveau.
//
// ⚠️ Donnée réelle constatée : les 518 versements existants ont tous
// `id_obligation=0` (jamais rattachés à un type de frais précis — sans
// doute une limite de l'ancien système/de la ressaisie). Demande utilisateur
// du 10/08/2026 : ces montants non ventilés doivent être considérés comme
// déjà imputés aux frais, du plus petit au plus grand montant (même règle
// que la Cotisation) — voir finances_soldes_simules() ci-dessous, seule
// source de vérité pour le solde par frais (affichage ET validation), qu'un
// versement précis soit renseigné ou non.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// Soldes « réels » par frais d'un niveau pour un élève donné : simule la
// consommation cumulée de TOUT ce qui a déjà été payé (rattaché à une
// obligation précise ou non), du plus petit frais au plus grand — pas
// seulement les versements déjà tagués sur CETTE obligation précise. Sans
// ça, un élève ayant réglé via d'anciens versements non ventilés pourrait
// payer une seconde fois le même frais. Retourne les lignes `obligation`
// (triées ASC par montant, montant_obligation déjà réduit) + 'solde'.
//
// $pourcentage_reduction (migration_v39, case "Cas social" de la fiche
// élève) : réduit chaque frais du niveau dans la même proportion — le
// pourcentage s'applique donc au total dû, quel que soit le découpage en
// plusieurs obligations (INSCRIPTION/SCOLARITÉ/APEE...).
function finances_soldes_simules(string $code_niveau, int $id_eleve, string $val_annee, float $pourcentage_reduction = 0.0): array {
    $obligations = db_all("SELECT * FROM obligation WHERE niveau_obligation=? ORDER BY montant_obligation ASC", [$code_niveau]);
    $consomme = (float) db_val(
        "SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_frais WHERE id_eleve=? AND val_annee=?",
        [$id_eleve, $val_annee]
    );
    $resultat = [];
    foreach ($obligations as $o) {
        $montant_oblig = round((float) $o['montant_obligation'] * (1 - $pourcentage_reduction / 100), 2);
        $couvert = min($consomme, $montant_oblig);
        $consomme -= $couvert;
        // Ordre important : le tableau à droite doit primer sur $o pour la
        // clé 'montant_obligation' — l'opérateur '+' de PHP garde la valeur
        // de l'OPÉRANDE DE GAUCHE en cas de collision de clé, donc "$o + [...]"
        // aurait gardé le montant NORMAL de $o au lieu du montant réduit
        // (bug constaté en test réel : le tableau affichait les montants
        // normaux malgré une réduction "Cas social" appliquée).
        $resultat[] = ['montant_obligation' => $montant_oblig, 'montant_normal' => (float) $o['montant_obligation'], 'solde' => $montant_oblig - $couvert] + $o;
    }
    return $resultat;
}

// ══════════════════════════════════════════════════════════════
//  POST — enregistrer / modifier / supprimer un versement
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action    = post('action');
    $id_eleve  = (int) post('id_eleve');
    $id_classe = (int) post('id_classe');

    if ($action === 'verser') {
        $id_obligation = (int) post('id_obligation');
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        if (!$id_eleve || !$id_classe || !$id_obligation || $montant <= 0 || !$date_paiement) {
            flash_set('erreur', 'Élève, obligation, montant (positif) et date sont requis.');
        } else {
            $oblig = db_one("SELECT * FROM obligation WHERE id_obligation=?", [$id_obligation]);
            $classe_row_v = db_one("SELECT Niveau FROM classe WHERE IDClasses=?", [$id_classe]);
            if (!$oblig || !$classe_row_v) {
                flash_set('erreur', 'Obligation ou classe introuvable.');
            } else {
                $soldes_simules = finances_soldes_simules($classe_row_v['Niveau'], $id_eleve, $val_annee, eleve_pourcentage_reduction($id_eleve));
                $ligne = current(array_filter($soldes_simules, fn($s) => (int) $s['id_obligation'] === $id_obligation));
                $solde = $ligne ? (float) $ligne['solde'] : 0.0;
                if ($montant > $solde + 0.01) {
                    flash_set('erreur', "Le montant saisi (" . number_format($montant, 0, ',', ' ') . " F) dépasse le solde dû pour « {$oblig['nom_obligation']} » (" . number_format($solde, 0, ',', ' ') . " F).");
                } else {
                    db_exec(
                        "INSERT INTO paiement_frais (id_eleve, classe, val_annee, id_obligation, montant_paiement, date_paiement, ref_paiement, mode_paiement, id_utilisateur)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$id_eleve, $id_classe, $val_annee, $id_obligation, $montant, $date_paiement, $ref, $mode_paiement, utilisateur_connecte()['id'] ?? null]
                    );
                    journaliser_action('paiement_saisi', null, number_format($montant, 0, ',', ' ') . ' F — ' . ($oblig['nom_obligation'] ?? ''));
                    flash_set('succes', 'Versement enregistré.');
                }
            }
        }
        rediriger("pages/finances/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    if ($action === 'modifier') {
        $id_pay        = (int) post('id_pay');
        $id_obligation = (int) post('id_obligation');
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        // Le versement doit appartenir à cet élève (défense en profondeur —
        // le formulaire ne propose que ceux de la fiche affichée).
        $existe = db_val("SELECT COUNT(*) FROM paiement_frais WHERE id_pay=? AND id_eleve=?", [$id_pay, $id_eleve]);
        if ($existe && $id_obligation && $montant > 0 && $date_paiement) {
            db_exec(
                "UPDATE paiement_frais SET id_obligation=?, montant_paiement=?, date_paiement=?, ref_paiement=?, mode_paiement=? WHERE id_pay=?",
                [$id_obligation, $montant, $date_paiement, $ref, $mode_paiement, $id_pay]
            );
            flash_set('succes', 'Versement modifié.');
        } else {
            flash_set('erreur', 'Versement introuvable ou données invalides.');
        }
        rediriger("pages/finances/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    if ($action === 'supprimer') {
        $id_pay = (int) post('id_pay');
        db_exec("DELETE FROM paiement_frais WHERE id_pay=? AND id_eleve=?", [$id_pay, $id_eleve]);
        flash_set('succes', 'Versement supprimé.');
        rediriger("pages/finances/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    // ── Cotisation : un seul montant, réparti automatiquement sur les
    //    frais du niveau du plus petit au plus grand (demande utilisateur
    //    du 10/08/2026) — pas de choix de frais à faire. Ex. 20 000 F sur
    //    APEE 1500/INSCRIPTION 5000/SCOLARITÉ 50000 → 1500 puis 5000 puis
    //    13500, dans cet ordre. Une ligne paiement_frais est créée par
    //    frais effectivement touché (jamais une seule ligne "mélangée" —
    //    chaque frais reste correctement imputé pour les statistiques).
    if ($action === 'cotiser') {
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        $classe_row = $id_classe ? db_one("SELECT Niveau FROM classe WHERE IDClasses=?", [$id_classe]) : null;

        if (!$id_eleve || !$id_classe || !$classe_row || $montant <= 0 || !$date_paiement) {
            flash_set('erreur', 'Élève, montant (positif) et date sont requis.');
        } else {
            $soldes = finances_soldes_simules($classe_row['Niveau'], $id_eleve, $val_annee, eleve_pourcentage_reduction($id_eleve));
            if (!$soldes) {
                flash_set('erreur', 'Aucune obligation configurée pour ce niveau (onglet Obligations).');
            } else {
                $total_solde = array_sum(array_column($soldes, 'solde'));
                if ($montant > $total_solde + 0.01) {
                    flash_set('erreur', "Le montant saisi (" . number_format($montant, 0, ',', ' ') . " F) dépasse le total restant dû (" . number_format($total_solde, 0, ',', ' ') . " F).");
                } else {
                    $restant = $montant;
                    $repartition = [];
                    foreach ($soldes as $s) {
                        if ($restant <= 0.009) break;
                        if ($s['solde'] <= 0) continue;
                        $part = min($restant, $s['solde']);
                        $repartition[] = ['nom' => $s['nom_obligation'], 'id' => $s['id_obligation'], 'montant' => $part];
                        $restant -= $part;
                    }
                    $id_agent = utilisateur_connecte()['id'] ?? null;
                    // Un versement réparti sur plusieurs frais reste UN SEUL paiement :
                    // toutes les lignes partagent le même id_versement (= id_pay de la
                    // première ligne insérée), pour n'avoir qu'un seul numéro de reçu
                    // (demande explicite du 12/09/2026, voir finances_numero_recu()).
                    $id_versement = null;
                    foreach ($repartition as $r) {
                        db_exec(
                            "INSERT INTO paiement_frais (id_versement, id_eleve, classe, val_annee, id_obligation, montant_paiement, date_paiement, ref_paiement, mode_paiement, id_utilisateur)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$id_versement, $id_eleve, $id_classe, $val_annee, $r['id'], $r['montant'], $date_paiement, $ref, $mode_paiement, $id_agent]
                        );
                        if ($id_versement === null) {
                            $id_versement = db_last_id();
                            db_exec("UPDATE paiement_frais SET id_versement=? WHERE id_pay=?", [$id_versement, $id_versement]);
                        }
                    }
                    $detail = implode(', ', array_map(
                        fn($r) => $r['nom'] . ' : ' . number_format($r['montant'], 0, ',', ' ') . ' F',
                        $repartition
                    ));
                    flash_set('succes', 'Versement de ' . number_format($montant, 0, ',', ' ') . " F réparti automatiquement — $detail.");
                }
            }
        }
        rediriger("pages/finances/versement.php?onglet=cotisation&classe=$id_classe&eleve=$id_eleve");
    }
}

// ══════════════════════════════════════════════════════════════
//  Affichage
// ══════════════════════════════════════════════════════════════
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$onglet    = $_GET['onglet'] ?? 'cotisation';
if (!in_array($onglet, ['cotisation', 'detail'], true)) $onglet = 'cotisation';

// Écriture réelle sur cette page (seul l'agent Comptable écrit sur Finances
// par défaut ; le propriétaire de l'association n'est jamais bridé — voir
// ecole_contexte.php::est_lecture_seule()/ecriture_module_permise()).
// Sert à masquer les boutons Modifier/Supprimer du tableau ci-dessous :
// sans ça, « Modifier » (bouton type=button, pas couvert par la règle CSS
// .lecture-seule) ouvrait la modale dont le bouton Enregistrer, lui, était
// déjà masqué — une impasse plutôt qu'une vraie lecture seule.
$peut_gerer_paiements = !(function_exists('est_lecture_seule') && est_lecture_seule());

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

$eleve = null; $classe = null; $obligations_vue = []; $historique = []; $total_du = 0.0; $total_paye = 0.0;
$pourcentage_reduction = 0.0;
if ($id_classe && $id_eleve && $val_annee) {
    $classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
    $eleve  = db_one(
        "SELECT e.* FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
         WHERE e.id_eleve=? AND i.IDClasses=? AND i.val_annee=?",
        [$id_eleve, $id_classe, $val_annee]
    );
    if ($eleve && $classe) {
        // Cas social (migration_v39) : pourcentage de réduction appliqué au
        // montant dû ci-dessous — voir eleve_pourcentage_reduction() (fonctions.php).
        $pourcentage_reduction = eleve_pourcentage_reduction($id_eleve);

        $obligations_niveau = db_all("SELECT * FROM obligation WHERE niveau_obligation=? ORDER BY nom_obligation", [$classe['Niveau']]);
        $ids_valides = array_column($obligations_niveau, 'id_obligation');

        $paye_par_obligation = [];
        foreach (db_all(
            "SELECT id_obligation, SUM(montant_paiement) AS paye FROM paiement_frais
             WHERE id_eleve=? AND val_annee=? GROUP BY id_obligation",
            [$id_eleve, $val_annee]
        ) as $r) {
            $paye_par_obligation[(int) $r['id_obligation']] = (float) $r['paye'];
        }

        $non_ventile = 0.0;
        foreach ($paye_par_obligation as $idobl => $mnt) {
            if (!in_array($idobl, $ids_valides, true)) $non_ventile += $mnt;
        }

        foreach ($obligations_niveau as $o) {
            $paye           = $paye_par_obligation[(int) $o['id_obligation']] ?? 0.0;
            $montant_reduit = round((float) $o['montant_obligation'] * (1 - $pourcentage_reduction / 100), 2);
            $solde          = max(0.0, $montant_reduit - $paye);
            $obligations_vue[] = $o + ['paye' => $paye, 'solde' => $solde, 'montant_reduit' => $montant_reduit];
            $total_du   += $montant_reduit;
            $total_paye += $paye;
        }
        $total_paye += $non_ventile;

        $historique = db_all(
            "SELECT p.*, o.nom_obligation FROM paiement_frais p
             LEFT JOIN obligation o ON o.id_obligation = p.id_obligation
             WHERE p.id_eleve=? AND p.val_annee=? ORDER BY p.date_paiement DESC, p.id_pay DESC",
            [$id_eleve, $val_annee]
        );
        // Répartition par mode de paiement de cet élève (aperçu rapide, onglet Cotisation).
        $modes_eleve = [];
        foreach ($historique as $p) {
            $mc = finances_mode_paiement_normalise($p['mode_paiement'] ?? null);
            $modes_eleve[$mc] = ($modes_eleve[$mc] ?? 0.0) + (float) $p['montant_paiement'];
        }

        // Soldes « réels » par frais (modale Détails des frais, dropdown de
        // l'onglet Détail, aperçu de l'onglet Cotisation) : voir
        // finances_soldes_simules() en tête de fichier — les versements non
        // ventilés (historique) y sont imputés aux frais du plus petit au
        // plus grand montant, il n'y a donc plus de « non ventilé » séparé.
        // Réduction "Cas social" déjà appliquée par le paramètre $pourcentage_reduction.
        $obligations_simulees = finances_soldes_simules($classe['Niveau'], $id_eleve, $val_annee, $pourcentage_reduction);
    }
}
$solde_global = $total_du - $total_paye;
$obligations_simulees ??= [];

// Mode « partiel » (AJAX) : réponse limitée au contenu de #versement-zone,
// sans header/sidebar/footer — même convention que pages/statistiques/index.php.
// Sert à la fois à la navigation (changement de classe/élève/onglet) et,
// via soumettreFormulaireAjax() (layout/footer.php), à l'enregistrement
// d'un versement sans recharger toute la page.
$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Versement';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="versement-zone">

<div class="page-titre">
  <h4><i class="bi bi-cash-coin me-1 text-primary"></i>Finances — Versement</h4>
  <div class="sub">Année <?= h($val_annee) ?></div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Classe</label>
        <select id="selClasse" class="form-select form-select-sm">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['IDClasses'] ?>" <?= $id_classe === (int) $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Élève</label>
        <select id="selEleve" class="form-select form-select-sm" <?= $id_classe ? '' : 'disabled' ?>>
          <option value="">— Choisir un élève —</option>
          <?php if ($id_classe): foreach (db_all(
              "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv FROM eleve e
               JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=?
               WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv",
              [$id_classe, $val_annee]
          ) as $e): ?>
            <option value="<?= (int) $e['id_eleve'] ?>" <?= $id_eleve === (int) $e['id_eleve'] ? 'selected' : '' ?>>
              <?= h($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')) ?> (<?= h($e['Mat_elv']) ?>)
            </option>
          <?php endforeach; endif; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<?php if ($eleve && $classe): ?>

<ul class="nav nav-tabs mb-2" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'cotisation' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/finances/versement.php?onglet=cotisation&classe=<?= $id_classe ?>&eleve=<?= $id_eleve ?>">
      <i class="bi bi-piggy-bank me-1"></i>Cotisation
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'detail' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/finances/versement.php?onglet=detail&classe=<?= $id_classe ?>&eleve=<?= $id_eleve ?>">
      <i class="bi bi-list-check me-1"></i>Détail par frais
    </a>
  </li>
</ul>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="fw-bold" style="font-size:1rem">
        <?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?>
        <?php if ($pourcentage_reduction > 0): ?>
          <span class="badge bg-info text-dark ms-1" title="Réduction appliquée au montant dû (fiche élève > Informations complémentaires)">
            <i class="bi bi-heart me-1"></i>Cas social — réduction <?= h(rtrim(rtrim(number_format($pourcentage_reduction, 2, '.', ''), '0'), '.')) ?>%
          </span>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-column gap-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirRecuFinances()"
                <?= empty($historique) ? 'disabled title="Aucun versement enregistré"' : '' ?>>
          <i class="bi bi-file-earmark-pdf me-1"></i>Reçu PDF
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalFrais">
          <i class="bi bi-list-ul me-1"></i>Détails des frais
        </button>
      </div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">MATRICULE</div><div class="fw-semibold" style="font-size:.85rem"><?= h($eleve['Mat_elv']) ?></div></div>
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">CLASSE</div><div class="fw-semibold" style="font-size:.85rem"><?= h($classe['DesignationClasses']) ?></div></div>
      <div class="col-6 col-md-3">
        <div class="text-muted" style="font-size:.68rem">TOTAL DÛ<?= $pourcentage_reduction > 0 ? ' (RÉDUIT)' : '' ?></div>
        <div class="fw-semibold" style="font-size:.85rem"><?= number_format($total_du, 0, ',', ' ') ?> F</div>
        <?php if ($pourcentage_reduction > 0): ?>
          <div class="text-muted" style="font-size:.68rem;text-decoration:line-through">Normal : <?= number_format(array_sum(array_column($obligations_niveau, 'montant_obligation')), 0, ',', ' ') ?> F</div>
        <?php endif; ?>
      </div>
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">SOLDE RESTANT</div><div class="fw-bold" style="font-size:.85rem;color:<?= $solde_global > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($solde_global, 0, ',', ' ') ?> F</div></div>
    </div>
    <?php if ($modes_eleve): ?>
      <div class="mt-2 pt-2 d-flex flex-wrap align-items-center gap-2" style="border-top:1px dashed #e5e7eb">
        <span class="text-muted" style="font-size:.68rem">PAYÉ VIA</span>
        <?php foreach ($modes_eleve as $code => $mnt): ?>
          <?= finances_mode_paiement_badge($code) ?> <span class="text-muted" style="font-size:.75rem">(<?= number_format($mnt, 0, ',', ' ') ?> F)</span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modale Détails des frais — soldes « réels » (voir $obligations_simulees) :
     tout versement déjà effectué, ventilé ou non, est imputé aux frais du
     plus petit au plus grand montant (même règle que la Cotisation) ; il
     n'y a donc plus de ligne « non ventilé » séparée ici — chaque montant
     déjà payé est rattaché à un frais précis, au moins virtuellement. -->
<div class="modal fade" id="modalFrais" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold"><i class="bi bi-list-ul me-1 text-primary"></i>Détails des frais — Niveau <?= h($classe['Niveau']) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0" style="font-size:.8rem">
            <thead style="background:#fbfbfd"><tr>
              <th>Frais</th>
              <?php if ($pourcentage_reduction > 0): ?><th class="text-end">Montant normal</th><?php endif; ?>
              <th class="text-end">Montant dû<?= $pourcentage_reduction > 0 ? ' (réduit)' : '' ?></th><th class="text-end">Déjà payé</th><th class="text-end">Solde</th>
            </tr></thead>
            <tbody>
              <?php if (!$obligations_simulees): ?>
                <tr><td colspan="<?= $pourcentage_reduction > 0 ? 5 : 4 ?>" class="text-center text-muted py-3">Aucune obligation configurée pour ce niveau (onglet Obligations).</td></tr>
              <?php else: foreach ($obligations_simulees as $o): $paye = (float) $o['montant_obligation'] - $o['solde']; ?>
                <tr>
                  <td><?= h($o['nom_obligation']) ?></td>
                  <?php if ($pourcentage_reduction > 0): ?><td class="text-end text-muted" style="text-decoration:line-through"><?= number_format((float) $o['montant_normal'], 0, ',', ' ') ?> F</td><?php endif; ?>
                  <td class="text-end"><?= number_format((float) $o['montant_obligation'], 0, ',', ' ') ?> F</td>
                  <td class="text-end"><?= number_format($paye, 0, ',', ' ') ?> F</td>
                  <td class="text-end fw-bold" style="color:<?= $o['solde'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($o['solde'], 0, ',', ' ') ?> F</td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <?php if ($obligations_simulees): ?>
            <tfoot><tr class="fw-bold"><td>TOTAL</td><?php if ($pourcentage_reduction > 0): ?><td></td><?php endif; ?><td class="text-end"><?= number_format($total_du, 0, ',', ' ') ?> F</td><td class="text-end"><?= number_format($total_paye, 0, ',', ' ') ?> F</td><td class="text-end"><?= number_format($solde_global, 0, ',', ' ') ?> F</td></tr></tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script>
// Reçu PDF : passe par la modale d'aperçu partagée (afficherApercu(),
// layout/footer.php) comme tous les autres PDF du projet — corrige une
// incohérence où ce bouton ouvrait pdf/finances_etat_classe.php/recu.php
// directement dans un nouvel onglet (target="_blank"), contrairement à la
// convention établie (bulletins, fiches élèves, cartes...).
function ouvrirRecuFinances() {
    afficherApercu(
        '<?= APP_URL ?>/pages/finances/recu.php?eleve=<?= $id_eleve ?>&classe=<?= $id_classe ?>',
        'Reçu de paiement', null, 'portrait'
    );
}
</script>

<?php if ($onglet === 'cotisation'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 1 — Cotisation : un seul montant, réparti
     automatiquement du plus petit au plus grand frais.
══════════════════════════════════════════════ -->
<?php if ($peut_gerer_paiements): ?>
<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Enregistrer une cotisation</span></div>
  <div class="card-body">
    <div class="alert alert-light border py-2 mb-3" style="font-size:.78rem">
      <i class="bi bi-info-circle me-1"></i>
      Pas besoin de choisir le frais : le montant saisi est réparti automatiquement sur les frais du niveau,
      du <strong>plus petit au plus grand</strong> montant, jusqu'à épuisement.
    </div>
    <form method="post" id="form-cotiser" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="cotiser">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Montant versé (FCFA)</label>
          <input type="number" name="montant_paiement" id="cot-montant" class="form-control form-control-sm" min="1" step="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement</label>
          <select name="mode_paiement" class="form-select form-select-sm">
            <?php foreach (finances_modes_paiement() as $code => $m): ?>
              <option value="<?= h($code) ?>" <?= $code === 'ESPECES' ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Référence</label>
          <input type="text" name="ref_paiement" class="form-control form-control-sm" placeholder="optionnel">
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        </div>
      </div>
    </form>
    <div id="cot-apercu" class="mt-3" style="font-size:.8rem;display:none">
      <div class="fw-semibold mb-1" style="color:#374151">Répartition prévue :</div>
      <div id="cot-apercu-lignes"></div>
    </div>
  </div>
</div>

<script>
// Aperçu client de la répartition (plus petit au plus grand) — même règle
// que le serveur, purement visuel, le serveur recalcule tout à l'enregistrement.
// var (pas const/let) : ce script est réexécuté à chaque rechargement AJAX
// de la zone (voir injecterHtmlDansZone(), layout/footer.php) — une
// redéclaration via let/const lèverait une erreur au 2e rechargement.
var COT_SOLDES = <?= json_encode(array_values(array_map(fn($o) => ['nom' => $o['nom_obligation'], 'solde' => $o['solde']], array_filter($obligations_simulees, fn($o) => $o['solde'] > 0)))) ?>;
document.getElementById('cot-montant').addEventListener('input', function() {
    const zone = document.getElementById('cot-apercu');
    const lignes = document.getElementById('cot-apercu-lignes');
    let montant = parseFloat(this.value) || 0;
    if (montant <= 0) { zone.style.display = 'none'; return; }
    let html = '';
    let restant = montant;
    for (const s of COT_SOLDES) {
        if (restant <= 0.009) break;
        const part = Math.min(restant, s.solde);
        restant -= part;
        html += '<div class="d-flex justify-content-between" style="max-width:320px"><span>' + s.nom + '</span><span class="fw-semibold">' + part.toLocaleString('fr-FR') + ' F</span></div>';
    }
    if (restant > 0.009) {
        html += '<div class="text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Dépasse le solde restant de ' + restant.toLocaleString('fr-FR') + ' F</div>';
    }
    lignes.innerHTML = html;
    zone.style.display = '';
});
</script>
<?php endif; // $peut_gerer_paiements ?>
<?php endif; // onglet === cotisation ?>

<?php if ($onglet === 'detail'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Détail par frais (formulaire existant, inchangé)
══════════════════════════════════════════════ -->
<?php if ($peut_gerer_paiements): ?>
<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Enregistrer un versement</span></div>
  <div class="card-body">
    <form method="post" id="form-verser" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="verser">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Frais</label>
          <select name="id_obligation" class="form-select form-select-sm" required>
            <option value="">— Choisir —</option>
            <?php foreach ($obligations_simulees as $o): if ($o['solde'] <= 0) continue; ?>
              <option value="<?= (int) $o['id_obligation'] ?>"><?= h($o['nom_obligation']) ?> (solde <?= number_format($o['solde'], 0, ',', ' ') ?> F)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Montant (FCFA)</label>
          <input type="number" name="montant_paiement" class="form-control form-control-sm" min="1" step="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement</label>
          <select name="mode_paiement" class="form-select form-select-sm">
            <?php foreach (finances_modes_paiement() as $code => $m): ?>
              <option value="<?= h($code) ?>" <?= $code === 'ESPECES' ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Référence</label>
          <input type="text" name="ref_paiement" class="form-control form-control-sm" placeholder="optionnel">
        </div>
        <div class="col-md-1">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg"></i></button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; // $peut_gerer_paiements ?>
<?php endif; // onglet === detail (le formulaire « Enregistrer un versement » lui est propre) ?>

<!-- Historique des versements : commun aux 2 onglets (Cotisation ET Détail)
     — demande explicite du 12/09/2026, avant visible seulement en Détail. -->
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Historique des versements</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr><th>N° reçu</th><th>Frais</th><th class="text-end">Montant</th><th>Mode</th><th>Date</th><th>Référence</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($historique as $p): ?>
          <tr>
            <td class="text-muted" style="font-size:.75rem"><?= h(finances_numero_recu(finances_id_versement($p))) ?></td>
            <td><?= $p['nom_obligation'] ? h($p['nom_obligation']) : '<span class="text-muted fst-italic">Non ventilé</span>' ?></td>
            <td class="text-end"><?= number_format((float) $p['montant_paiement'], 0, ',', ' ') ?> F</td>
            <td><?= finances_mode_paiement_badge($p['mode_paiement'] ?? null) ?></td>
            <td><?= h(date_fr($p['date_paiement'])) ?></td>
            <td><?= h($p['ref_paiement'] ?: '—') ?></td>
            <td class="text-center">
              <?php if ($peut_gerer_paiements): ?>
              <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Modifier"
                      onclick='ouvrirModifier(<?= json_encode([
                          "id_pay" => (int) $p["id_pay"], "id_obligation" => (int) $p["id_obligation"],
                          "montant" => (float) $p["montant_paiement"], "date" => $p["date_paiement"], "ref" => $p["ref_paiement"],
                          "mode_paiement" => finances_mode_paiement_normalise($p["mode_paiement"] ?? null),
                      ]) ?>)'>
                <i class="bi bi-pencil-square text-success" style="font-size:.78rem"></i>
              </button>
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce versement ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id_pay" value="<?= (int) $p['id_pay'] ?>">
                <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
                <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
                <button type="submit" class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer">
                  <i class="bi bi-trash text-danger" style="font-size:.78rem"></i>
                </button>
              </form>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$historique): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Aucun versement enregistré.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($historique): ?>
      <tfoot><tr class="fw-bold"><td></td><td>TOTAL</td><td class="text-end"><?= number_format($total_paye, 0, ',', ' ') ?> F</td><td colspan="4"></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Modale Modifier -->
<div class="modal fade" id="modalVersement" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1 text-primary"></i>Modifier le versement</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="modifier">
        <input type="hidden" name="id_pay" id="mv-id">
        <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
        <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
        <div class="modal-body row g-2">
          <div class="col-12">
            <label class="form-label">Frais</label>
            <select name="id_obligation" id="mv-obligation" class="form-select" required>
              <?php foreach ($obligations_niveau as $o): ?>
                <option value="<?= (int) $o['id_obligation'] ?>"><?= h($o['nom_obligation']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Montant</label>
            <input type="number" name="montant_paiement" id="mv-montant" class="form-control" min="1" required>
          </div>
          <div class="col-6">
            <label class="form-label">Mode de paiement</label>
            <select name="mode_paiement" id="mv-mode" class="form-select">
              <?php foreach (finances_modes_paiement() as $code => $m): ?>
                <option value="<?= h($code) ?>"><?= h($m['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="date" name="date_paiement" id="mv-date" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">Référence</label>
            <input type="text" name="ref_paiement" id="mv-ref" class="form-control">
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ouvrirModifier(p) {
    document.getElementById('mv-id').value = p.id_pay;
    document.getElementById('mv-obligation').value = p.id_obligation || '';
    document.getElementById('mv-montant').value = p.montant;
    document.getElementById('mv-mode').value = p.mode_paiement || 'ESPECES';
    document.getElementById('mv-date').value = p.date;
    document.getElementById('mv-ref').value = p.ref || '';
    new bootstrap.Modal(document.getElementById('modalVersement')).show();
}
</script>

<?php endif; // eleve && classe ?>

<script>
// var (pas const/let) : ce script est réexécuté à chaque rechargement AJAX
// de la zone #versement-zone (voir injecterHtmlDansZone(), layout/footer.php).
var selClasse = document.getElementById('selClasse');
var selEleve  = document.getElementById('selEleve');
var ONGLET_COURANT = <?= json_encode($onglet) ?>;
selClasse.addEventListener('change', function() {
    var classeId = this.value;
    var url = '<?= APP_URL ?>/pages/finances/versement.php?onglet=' + ONGLET_COURANT
        + (classeId ? '&classe=' + encodeURIComponent(classeId) : '');
    chargerPartiel(url, 'versement-zone');
});
selEleve.addEventListener('change', function() {
    if (!this.value) return;
    var url = '<?= APP_URL ?>/pages/finances/versement.php?onglet=' + ONGLET_COURANT
        + '&classe=' + encodeURIComponent(selClasse.value) + '&eleve=' + encodeURIComponent(this.value);
    chargerPartiel(url, 'versement-zone');
});
</script>

</div><!-- /#versement-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'versement-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
