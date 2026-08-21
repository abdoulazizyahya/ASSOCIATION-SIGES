<?php
// dashboard.php — Tableau de bord : vue d'ensemble de TOUT le système en un
// coup d'œil (demande du 20/08/2026, enrichie le même jour avec des
// diagrammes Chart.js + cellules plus compactes) — Scolarité (effectifs),
// Pédagogie (moyenne générale, taux de réussite, meilleurs élèves),
// Finances (inscriptions/paiements, cas sociaux), Dépenses, Paie/RH. Les 3
// dernières sections sont sensibles (argent/salaires) et restent réservées
// à DIRECTEUR/SECRETAIRE — mêmes rôles que les menus Finances/Dépenses/Paie
// eux-mêmes (voir layout/header.php) : ce n'est pas parce que c'est sur le
// dashboard que ça doit être visible plus largement que le menu d'origine.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/notes_apc.php';
require_once __DIR__ . '/paie_fonctions.php'; // solde_avance()
exiger_connexion();

$role      = role_connecte();
$is_admin  = in_array($role, ['DIRECTEUR', 'SECRETAIRE'], true);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$seq_act   = get_sequence_active();

// ── Scolarité ─────────────────────────────────────────────────────
$nb_eleves = (int) db_val(
    "SELECT COUNT(DISTINCT id_eleve) FROM inscrire WHERE val_annee=?", [$val_annee]
);
$nb_garcons = (int) db_val(
    "SELECT COUNT(DISTINCT i.id_eleve) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve
     WHERE i.val_annee=? AND e.Sexe_elv LIKE 'M%'", [$val_annee]
);
$nb_filles = (int) db_val(
    "SELECT COUNT(DISTINCT i.id_eleve) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve
     WHERE i.val_annee=? AND e.Sexe_elv LIKE 'F%'", [$val_annee]
);
$nb_classes     = (int) db_val("SELECT COUNT(*) FROM classe");
$nb_enseignants = (int) db_val("SELECT COUNT(*) FROM enseignant WHERE id_fonction='ENSEIGNANT'");

$par_classe = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau,
            COUNT(DISTINCT i.id_eleve) AS effectif,
            SUM(e.Sexe_elv LIKE 'M%') AS nb_m,
            SUM(e.Sexe_elv LIKE 'F%') AS nb_f
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?
     LEFT JOIN eleve e ON e.id_eleve=i.id_eleve
     GROUP BY c.IDClasses
     ORDER BY n.OrdreNiveau",
    [$val_annee]
);

// ── Pédagogie : moyenne générale + taux de réussite école entière (trimestre
// en cours), palmarès, répartition par niveau, meilleure classe, Tableau
// d'honneur — enrichi le 20/08/2026 pour une carte en une seule colonne,
// dense en informations utiles. Visible à tous (mêmes données que la page
// Statistiques, pas plus sensible). ──────────────────────────────────
$id_trim  = (int) ($seq_act['id_trim'] ?? 0);
$moy_generale_ecole = null; $taux_reussite_ecole = 0; $nb_evalues_ecole = 0; $palmares = [];
$nb_tableau_honneur = 0; $meilleure_classe = null; $par_niveau_moy = [];
if ($id_trim) {
    $classes_toutes = db_all(
        "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
         FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
         JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?
         GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
         ORDER BY n.OrdreNiveau",
        [$val_annee]
    );
    $somme_ponderee = 0.0; $nb_admis_ecole = 0;
    $niveau_somme = []; $niveau_nb = []; // pour la moyenne pondérée par niveau
    foreach ($classes_toutes as $c) {
        $b = bilan_classe_genre((int) $c['IDClasses'], $val_annee, 'trim', $id_trim);
        $nb_evalues_ecole   += $b['classes']['T'];
        $nb_admis_ecole     += $b['moy_ge10']['T'];
        $nb_tableau_honneur += $b['tab']['T'];
        if ($b['moy_gen']['T'] !== null) {
            $somme_ponderee += $b['moy_gen']['T'] * $b['classes']['T'];
            if ($meilleure_classe === null || $b['moy_gen']['T'] > $meilleure_classe['moy']) {
                $meilleure_classe = ['nom' => $c['DesignationClasses'], 'moy' => $b['moy_gen']['T']];
            }
            $niv = $c['Niveau'] ?: 'Non défini';
            $niveau_somme[$niv] = ($niveau_somme[$niv] ?? 0.0) + $b['moy_gen']['T'] * $b['classes']['T'];
            $niveau_nb[$niv]    = ($niveau_nb[$niv] ?? 0) + $b['classes']['T'];
        }
    }
    if ($nb_evalues_ecole > 0) {
        $moy_generale_ecole  = round($somme_ponderee / $nb_evalues_ecole, 2);
        $taux_reussite_ecole = round($nb_admis_ecole / $nb_evalues_ecole * 100, 1);
    }
    foreach ($niveau_nb as $niv => $nb) {
        if ($nb > 0) $par_niveau_moy[] = ['niveau' => $niv, 'moy' => round($niveau_somme[$niv] / $nb, 2)];
    }
    $palmares = db_all(
        "SELECT mt.id_eleve, mt.moy, c.DesignationClasses, e.Nom_elv, e.Prenom_elv, e.Sexe_elv
         FROM moyenne_trimestre mt
         JOIN eleve e ON e.id_eleve = mt.id_eleve
         JOIN classe c ON c.IDClasses = mt.classe
         JOIN inscrire i ON i.id_eleve = mt.id_eleve AND i.IDClasses = mt.classe AND i.val_annee = mt.val_annee
         WHERE mt.val_annee = ? AND mt.id_trim = ? AND mt.classable = 1 AND mt.moy IS NOT NULL
         ORDER BY mt.moy DESC LIMIT 5",
        [$val_annee, $id_trim]
    );
}

// ── Finances (inscriptions/paiements) + Cas sociaux — DIRECTEUR/SECRETAIRE ──
$total_du = 0.0; $total_normal = 0.0; $total_paye = 0.0; $nb_cas_sociaux = 0;
if ($is_admin) {
    foreach (finances_du_par_eleve($val_annee) as $e) {
        $total_du     += $e['du'];
        $total_normal += $e['montant_normal'];
        if ($e['cas_social']) $nb_cas_sociaux++;
    }
    $total_paye = (float) db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_frais WHERE val_annee=?", [$val_annee]);
}
$taux_recouvrement = $total_du > 0 ? round($total_paye / $total_du * 100, 1) : 0;
$reduction_cas_sociaux = $total_normal - $total_du;

// ── Dépenses — DIRECTEUR/SECRETAIRE ─────────────────────────────────
$total_depenses = 0.0; $solde_caisse_val = 0.0;
if ($is_admin) {
    $total_depenses   = (float) db_val("SELECT COALESCE(SUM(montant),0) FROM depense WHERE val_annee=?", [$val_annee]);
    $solde_caisse_val = solde_caisse($val_annee);
}

// ── Paie / RH — dernière période créée — DIRECTEUR/SECRETAIRE ──────────
$derniere_periode = null; $avances_encours = 0.0;
if ($is_admin) {
    $derniere_periode = db_one(
        "SELECT p.*, COUNT(b.id) AS nb_bulletins, COALESCE(SUM(b.net_a_payer),0) AS total_net,
                SUM(CASE WHEN b.statut='Payé' THEN 1 ELSE 0 END) AS nb_payes
         FROM periode_paie p LEFT JOIN bulletin_paie b ON b.id_periode=p.id
         GROUP BY p.id ORDER BY p.annee DESC, p.mois DESC LIMIT 1"
    );
    foreach (db_all("SELECT id FROM avance_salaire") as $a) {
        $avances_encours += solde_avance((int) $a['id']);
    }
}
$taux_bulletins_payes = ($derniere_periode && (int) $derniere_periode['nb_bulletins'] > 0)
    ? round((int) $derniere_periode['nb_payes'] / (int) $derniere_periode['nb_bulletins'] * 100, 1) : 0;

$fmt_moy = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$fmt_f   = fn(float $v): string => number_format($v, 0, ',', ' ') . ' F';

// Couleurs des diagrammes (Chart.js) reprises telles quelles du système de
// design existant (badge-m/badge-f, --ok/--warn/--danger/--muted) pour
// rester cohérent avec le reste de l'appli, jamais de nouvelle palette. Le
// rapprochement Dépenses vs Encaissé utilise un gris neutre plutôt que
// l'orange "warn" pour éviter une paire vert/orange (risque de confusion en
// daltonisme rouge-vert) — chaque barre reste de toute façon étiquetée par
// sa légende ET son chiffre en texte, jamais la couleur seule.

$titre_page = 'Tableau de bord';
require_once __DIR__ . '/layout/header.php';
?>
<style>
/* ── Tableau de bord : cellules plus compactes (portée à cette page
   uniquement, via .dash-page — ne touche pas .stat-card ailleurs, ex.
   pages/notes_arabe/classement.php qui réutilise la même classe globale). */
.dash-page .stat-card{padding:.55rem .7rem;gap:.6rem}
.dash-page .stat-icon{width:34px;height:34px;font-size:1rem}
.dash-page .stat-val{font-size:1.2rem}
.dash-page .stat-lbl{font-size:.66rem}
.dash-page .card-header{padding:.4rem .75rem}
.dash-page .mini-stat{padding:2px}
.dash-page .mini-stat .v{font-size:1rem;font-weight:800;font-family:var(--font-heading);color:var(--primary);line-height:1.15}
.dash-page .mini-stat .l{font-size:.63rem;color:var(--muted);text-transform:uppercase;letter-spacing:.02em}
.dash-page .card-body{padding:.65rem .75rem}
.dash-page .table-abz td, .dash-page .table-abz th{padding:4px 8px;font-size:.74rem}
.dash-page .palmares-rang{width:22px;height:22px;font-size:.7rem}
.gauge-wrap{position:relative;width:64px;height:64px;flex-shrink:0}
.gauge-wrap .gauge-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;font-family:var(--font-heading);color:var(--primary)}

/* ── Pédagogie enrichie — bandeau héros + puces + podium (20/08/2026) ── */
.pedago-hero{
  display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap;
  background:linear-gradient(120deg,var(--primary) 0%,var(--primary-2) 100%);
  border-radius:var(--radius);padding:1rem 1.2rem;color:#fff;
}
.gauge-wrap-lg{width:84px;height:84px}
.gauge-val-lg{font-size:1.15rem;color:#fff;flex-direction:column;line-height:1.05}
.gauge-val-lg small{font-size:.55rem;font-weight:600;opacity:.8;text-transform:uppercase;letter-spacing:.03em}
.pedago-hero-moy-wrap{padding-right:1rem;border-right:1px solid rgba(255,255,255,.22)}
.pedago-hero-moy{font-size:2.3rem;font-weight:800;font-family:var(--font-heading);color:var(--gold-light);line-height:1}
.pedago-hero-moy span{font-size:1.05rem;opacity:.75;font-weight:600}
.pedago-hero-lbl{font-size:.68rem;opacity:.82;max-width:190px}
.pedago-chips{display:flex;flex-wrap:wrap;gap:.5rem;flex:1}
.pedago-chip{
  display:flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.1);
  border:1px solid rgba(255,255,255,.16);border-radius:8px;padding:.4rem .65rem;min-width:110px;
}
.pedago-chip i{font-size:1rem;opacity:.9}
.pedago-chip b{display:block;font-size:.95rem;font-weight:800;font-family:var(--font-heading);line-height:1.1}
.pedago-chip span{display:block;font-size:.62rem;opacity:.82;text-transform:uppercase;letter-spacing:.02em}
.pedago-subcard{background:var(--hover-bg);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem .9rem}
.pedago-subcard-title{font-weight:700;font-size:.78rem;color:var(--primary);margin-bottom:.5rem}
.leaderboard-row{display:flex;align-items:center;gap:.6rem;padding:.4rem 0;border-bottom:1px dashed var(--border)}
.leaderboard-row:last-child{border-bottom:0}
.leaderboard-rang{width:24px;height:24px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.7rem}
.leaderboard-avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--info-bg);color:var(--primary);font-weight:800;font-size:.7rem}
.min-w-0{min-width:0}

/* ── Bandeau unifié « à première vue » — Effectifs + Finances + Pédagogie
   en un seul coup d'œil (demande du 20/08/2026, suite) ── */
.hero-banner{display:flex;flex-wrap:wrap;background:linear-gradient(120deg,var(--primary) 0%,var(--primary-2) 100%);border-radius:var(--radius);color:#fff;box-shadow:var(--card-shadow)}
.hero-zone{flex:1;min-width:230px;padding:.8rem 1.15rem;display:flex;flex-direction:column;gap:.55rem}
.hero-zone-finance{flex:1.5;min-width:360px}
.hero-zone-head{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;opacity:.85;display:flex;align-items:center;gap:.4rem}
.hero-zone-nums{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.hero-divider{width:1px;background:rgba(255,255,255,.18);align-self:stretch;margin:.7rem 0}
.hn{line-height:1.12}
.hn b{display:block;font-size:1.15rem;font-weight:800;font-family:var(--font-heading)}
.hn span{display:block;font-size:.62rem;opacity:.8;text-transform:uppercase;letter-spacing:.02em}
.gauge-wrap-sm{width:50px;height:50px}
.gauge-val-sm{font-size:.7rem;color:#fff}
</style>

<div class="dash-page">

<div class="page-titre">
  <h4><i class="bi bi-speedometer2 me-1 text-primary"></i>Tableau de bord</h4>
  <div class="sub">Année scolaire <?= h($val_annee) ?><?= $seq_act ? ' — ' . h($seq_act['libelle_seq']) . ' en cours' : '' ?></div>
</div>

<!-- ── Bandeau unifié « à première vue » : Effectifs + Finances + Pédagogie ── -->
<div class="hero-banner mb-3">
  <div class="hero-zone">
    <div class="hero-zone-head"><i class="bi bi-people-fill"></i>Effectifs</div>
    <div class="hero-zone-nums">
      <div class="gauge-wrap gauge-wrap-sm"><canvas id="chartEffectifsPie"></canvas></div>
      <div class="hn"><b><?= $nb_eleves ?></b><span>Élèves</span></div>
      <div class="hn"><b style="color:#a9c6ff"><?= $nb_garcons ?></b><span>Garçons</span></div>
      <div class="hn"><b style="color:#ffb3cc"><?= $nb_filles ?></b><span>Filles</span></div>
      <div class="hn"><b><?= $nb_classes ?></b><span>Classes</span></div>
    </div>
  </div>
  <?php if ($is_admin): ?>
  <div class="hero-divider"></div>
  <div class="hero-zone hero-zone-finance">
    <div class="hero-zone-head"><i class="bi bi-cash-coin"></i>Finances — Paiements &amp; Dépenses</div>
    <div class="hero-zone-nums">
      <div class="gauge-wrap gauge-wrap-sm"><canvas id="chartRecouvrementTop"></canvas><div class="gauge-val gauge-val-sm"><?= $taux_recouvrement ?>%</div></div>
      <div class="hn"><b><?= $fmt_f($total_du) ?></b><span>Total dû</span></div>
      <div class="hn"><b style="color:#7fe0ab"><?= $fmt_f($total_paye) ?></b><span>Encaissé</span></div>
      <div class="hn"><b style="color:#ffcf8a"><?= $fmt_f($total_depenses) ?></b><span>Dépensé</span></div>
      <div class="hn"><b style="color:<?= $solde_caisse_val >= 0 ? '#7fe0ab' : '#ff9d9d' ?>"><?= $fmt_f($solde_caisse_val) ?></b><span>Solde de caisse</span></div>
    </div>
  </div>
  <?php endif; ?>
  <div class="hero-divider"></div>
  <div class="hero-zone">
    <div class="hero-zone-head"><i class="bi bi-mortarboard-fill"></i>Pédagogie</div>
    <div class="hero-zone-nums">
      <?php if ($id_trim): ?>
      <div class="gauge-wrap gauge-wrap-sm"><canvas id="chartReussite"></canvas><div class="gauge-val gauge-val-sm"><?= $taux_reussite_ecole ?>%</div></div>
      <div class="hn"><b><?= $fmt_moy($moy_generale_ecole) ?>/20</b><span>Moyenne</span></div>
      <div class="hn"><b><?= $nb_evalues_ecole ?></b><span>Évalués</span></div>
      <?php else: ?>
      <div class="hn" style="font-size:.75rem;opacity:.85">Aucun trimestre en cours</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- ── 1. Paiements et dépenses ── -->
<div class="row g-2 mb-3">
  <!-- ── Finances ── -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-cash-coin me-1" style="color:var(--ok)"></i>Finances</span>
        <a href="<?= APP_URL ?>/pages/finances/statistiques.php" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="gauge-wrap"><canvas id="chartRecouvrement"></canvas><div class="gauge-val"><?= $taux_recouvrement ?>%</div></div>
          <div class="flex-grow-1 row g-1">
            <div class="col-6 mini-stat"><div class="v"><?= $fmt_f($total_du) ?></div><div class="l">Total dû</div></div>
            <div class="col-6 mini-stat"><div class="v text-success"><?= $fmt_f($total_paye) ?></div><div class="l">Encaissé</div></div>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center pt-2" style="border-top:1px solid var(--border);font-size:.76rem">
          <span><i class="bi bi-heart me-1" style="color:var(--danger)"></i>Cas sociaux</span>
          <span class="fw-bold"><?= $nb_cas_sociaux ?> élève<?= $nb_cas_sociaux > 1 ? 's' : '' ?><?= $nb_cas_sociaux > 0 ? ' · -' . $fmt_f($reduction_cas_sociaux) : '' ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Dépenses ── -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-cart-dash me-1" style="color:var(--warn)"></i>Dépenses</span>
        <a href="<?= APP_URL ?>/pages/depenses/journal.php" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body">
        <div style="height:60px"><canvas id="chartDepenses"></canvas></div>
        <div class="d-flex justify-content-between align-items-center pt-2 mt-1" style="border-top:1px solid var(--border);font-size:.76rem">
          <span>Solde de caisse</span>
          <span class="fw-bold <?= $solde_caisse_val >= 0 ? 'text-success' : 'text-danger' ?>"><?= $fmt_f($solde_caisse_val) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Paie / RH ── -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-wallet2 me-1" style="color:var(--purple)"></i>Paie</span>
        <a href="<?= APP_URL ?>/pages/paie/index.php" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body">
        <?php if ($derniere_periode): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="gauge-wrap"><canvas id="chartBulletins"></canvas><div class="gauge-val"><?= (int) $derniere_periode['nb_payes'] ?>/<?= (int) $derniere_periode['nb_bulletins'] ?></div></div>
          <div class="flex-grow-1 row g-1">
            <div class="col-12 mini-stat"><div class="v"><?= $fmt_f((float) $derniere_periode['total_net']) ?></div><div class="l">Masse salariale — <?= h($derniere_periode['libelle']) ?></div></div>
          </div>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-2" style="font-size:.78rem">Aucune période de paie créée.</div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center pt-2" style="border-top:1px solid var(--border);font-size:.76rem">
          <span><i class="bi bi-cash me-1"></i>Avances en cours</span>
          <span class="fw-bold"><?= $fmt_f($avances_encours) ?></span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── 2. Pédagogie (détail) — moyenne/réussite déjà au bandeau du haut,
     ici le détail : chiffres clés, répartition par niveau, palmarès ── -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-mortarboard me-1" style="color:var(--info)"></i>Pédagogie — détail</span>
    <a href="<?= APP_URL ?>/pages/statistiques/index.php" class="btn btn-sm btn-abz-outline" style="font-size:.72rem">Statistiques <i class="bi bi-arrow-right ms-1"></i></a>
  </div>
  <div class="card-body">
    <?php if (!$id_trim): ?>
      <div class="text-muted text-center py-2" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Aucun trimestre en cours (aucune séquence active).</div>
    <?php else: ?>

    <!-- Puces de chiffres clés (Moyenne/Réussite déjà dans le bandeau du haut) -->
    <div class="pedago-subcard mb-3">
      <div class="pedago-chips" style="color:var(--primary)">
        <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)"><i class="bi bi-check-circle text-success"></i><div><b><?= $nb_admis_ecole ?></b><span>Admis</span></div></div>
        <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)"><i class="bi bi-x-circle text-danger"></i><div><b><?= $nb_evalues_ecole - $nb_admis_ecole ?></b><span>Recalés</span></div></div>
        <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)"><i class="bi bi-award" style="color:var(--gold)"></i><div><b><?= $nb_tableau_honneur ?></b><span>Tableau d'honneur</span></div></div>
        <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)"><i class="bi bi-person-x text-muted"></i><div><b><?= $nb_eleves - $nb_evalues_ecole ?></b><span>Non évalués</span></div></div>
        <?php if ($meilleure_classe): ?>
        <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)"><i class="bi bi-star-fill" style="color:var(--gold)"></i><div><b><?= h($meilleure_classe['nom']) ?></b><span>Meilleure classe (<?= $fmt_moy($meilleure_classe['moy']) ?>/20)</span></div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <!-- Répartition par niveau -->
      <?php if ($par_niveau_moy): ?>
      <div class="col-lg-5">
        <div class="pedago-subcard h-100">
          <div class="pedago-subcard-title"><i class="bi bi-diagram-3 me-1"></i>Moyenne par niveau</div>
          <div style="height:<?= max(90, count($par_niveau_moy) * 30) ?>px"><canvas id="chartNiveaux"></canvas></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Palmarès -->
      <div class="<?= $par_niveau_moy ? 'col-lg-7' : 'col-12' ?>">
        <div class="pedago-subcard h-100">
          <div class="pedago-subcard-title"><i class="bi bi-trophy me-1" style="color:var(--gold)"></i>Meilleurs élèves de l'établissement</div>
          <?php if ($palmares): ?>
            <?php foreach ($palmares as $i => $p): $rg = $i + 1;
              $couleur = $rg === 1 ? '#c8960a' : ($rg === 2 ? '#9ca3af' : ($rg === 3 ? '#b06a35' : '#c7d8f0'));
              $moy_p = (float) $p['moy'];
              $init = mb_strtoupper(mb_substr($p['Nom_elv'], 0, 1) . mb_substr($p['Prenom_elv'] ?? '', 0, 1));
            ?>
            <div class="leaderboard-row">
              <div class="leaderboard-rang" style="background:<?= $couleur ?>"><?= $rg <= 3 ? '<i class="bi bi-award-fill"></i>' : $rg ?></div>
              <div class="leaderboard-avatar"><?= h($init) ?></div>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-truncate"><?= h(mb_strtoupper($p['Nom_elv']) . ' ' . ($p['Prenom_elv'] ?? '')) ?></div>
                <div class="text-muted" style="font-size:.68rem"><?= h($p['DesignationClasses']) ?></div>
              </div>
              <div class="text-end" style="min-width:70px">
                <div class="fw-bold text-success"><?= $fmt_moy($moy_p) ?>/20</div>
                <div class="progress" style="height:4px"><div class="progress-bar bg-success" style="width:<?= min(100, $moy_p / 20 * 100) ?>%"></div></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-muted text-center py-2" style="font-size:.8rem">Aucune moyenne calculée pour ce trimestre.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── 3. Scolarité : effectifs par classe (renvoyé en bas, demande du 20/08/2026) ── -->
<div class="row g-2 mt-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-bar-chart-line me-1"></i>Effectifs par classe</div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0">
          <thead>
            <tr><th>Classe</th><th class="text-center">G</th><th class="text-center">F</th><th class="text-center fw-bold">Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($par_classe as $c): ?>
            <tr>
              <td class="fw-semibold"><?= h($c['DesignationClasses']) ?></td>
              <td class="text-center"><span class="badge-m"><?= (int)$c['nb_m'] ?></span></td>
              <td class="text-center"><span class="badge-f"><?= (int)$c['nb_f'] ?></span></td>
              <td class="text-center fw-bold"><?= (int)$c['effectif'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-person-badge me-1"></i>Personnel</div>
      <div class="card-body">
        <div class="d-flex justify-content-between py-1" style="font-size:.82rem">
          <span class="text-muted">Enseignant(e)s</span><span class="fw-bold"><?= $nb_enseignants ?></span>
        </div>
        <div class="d-flex justify-content-between py-1" style="font-size:.82rem">
          <span class="text-muted">Direction</span>
          <span class="fw-bold"><?= h(get_etablissement()['fonction_dirigeant_fr'] ?? '—') ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /.dash-page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Segoe UI','Inter',system-ui,sans-serif";
Chart.defaults.font.size = 11;

// Petite jauge en anneau (valeur/reste) — réutilisée pour Réussite,
// Recouvrement et Bulletins payés. Le reste est toujours gris neutre (une
// "piste"), jamais une 2e couleur porteuse de sens — seule la valeur est
// une couleur de statut (vert si le seuil est atteint, sinon or/orange).
function gaugeDoughnut(id, valeur, seuilBon) {
    const el = document.getElementById(id);
    if (!el) return;
    const couleur = valeur >= seuilBon ? '#1e7c50' : '#c8960a';
    new Chart(el, {
        type: 'doughnut',
        data: { datasets: [{ data: [valeur, Math.max(0, 100 - valeur)], backgroundColor: [couleur, '#e2ded0'], borderWidth: 0 }] },
        options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: { duration: 400 } }
    });
}
gaugeDoughnut('chartReussite', <?= (float) $taux_reussite_ecole ?>, 50);

<?php if ($par_niveau_moy): ?>
new Chart(document.getElementById('chartNiveaux'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($n) => 'Niveau ' . $n['niveau'], $par_niveau_moy)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($n) => $n['moy'], $par_niveau_moy)) ?>,
            backgroundColor: <?= json_encode(array_map(fn($n) => $n['moy'] >= 10 ? '#1e7c50' : '#c8960a', $par_niveau_moy)) ?>,
            borderRadius: 4, barThickness: 16,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, max: 20, grid: { color: '#e2ded0' } },
            y: { grid: { display: false } },
        },
    },
});
<?php endif; ?>

<?php if ($is_admin): ?>
gaugeDoughnut('chartRecouvrementTop', <?= (float) $taux_recouvrement ?>, 50);
gaugeDoughnut('chartRecouvrement', <?= (float) $taux_recouvrement ?>, 50);
gaugeDoughnut('chartBulletins', <?= (float) $taux_bulletins_payes ?>, 100);

new Chart(document.getElementById('chartDepenses'), {
    type: 'bar',
    data: {
        labels: ['Encaissé', 'Dépensé'],
        datasets: [{
            data: [<?= (float) $total_paye ?>, <?= (float) $total_depenses ?>],
            backgroundColor: ['#1e7c50', '#6b7280'],
            borderRadius: 4, barThickness: 22,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr-FR') + ' F' }, grid: { color: '#f2f0e8' } },
            y: { grid: { display: false } },
        },
    },
});
<?php endif; ?>

// Répartition Garçons/Filles — diagramme circulaire compact du bandeau
// Effectifs (remplace l'histogramme par classe, demande du 20/08/2026, suite).
new Chart(document.getElementById('chartEffectifsPie'), {
    type: 'doughnut',
    data: {
        labels: ['Garçons', 'Filles'],
        datasets: [{ data: [<?= $nb_garcons ?>, <?= $nb_filles ?>], backgroundColor: ['#a9c6ff', '#ffb3cc'], borderWidth: 0 }]
    },
    options: {
        cutout: '60%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.label + ' : ' + ctx.parsed } },
        },
    },
});
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
