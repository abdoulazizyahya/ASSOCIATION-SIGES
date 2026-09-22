<?php
// secondaire/dashboard_contenu.php — tableau de bord secondaire, COPIE
// CONFORME de dashboard.php (primaire) : même CSS, même structure HTML,
// mêmes widgets (bandeau héros, situation financière, top impayés,
// Finances/Dépenses/Paie, dépenses par catégorie + évolution encaissements,
// Pédagogie détail avec palmarès, effectifs & finances par classe,
// personnel + recouvrement par classe) — demande explicite du 22/09/2026 :
// "on ne doit pas faire la différence [...] la différence sera juste au
// niveau des données affichées". Seule la SOURCE des données change (tables
// secondaire, statut public/privé pour Finances/Dépenses — voir
// layout/menu_secondaire.php), jamais le design/CSS/disposition.
// Inclus par dashboard.php (racine) quand type_enseignement_courant() ===
// 'secondaire', APRÈS exiger_connexion() — connexion.php/fonctions.php
// déjà chargés par l'appelant.

$role = role_connecte();
$statut_ecole = get_etablissement()['statut'] ?? 'public';
// Mêmes rôles que layout/menu_secondaire.php pour les groupes PAIEMENT
// PUBLIQUE/PRIVÉ et Ressources humaines — pas de SECRETAIRE/COMPTABLE ici
// (rôles primaire), ADMIN/PROVISEUR/FONDATEUR/CENSEUR/INTENDANT côté secondaire.
$peut_voir_finances  = function_exists('capacite_finances') ? capacite_finances() : false;
$peut_voir_paie      = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT'], true);
// Pédagogie masquée pour un agent purement financier (INTENDANT) — même
// principe que $peut_voir_pedagogie côté primaire (masqué pour COMPTABLE
// seul). Pas de cloisonnement par classes visibles pour l'instant (pas
// d'équivalent secondaire de classes_ids_visibles() — hors scope ici).
$peut_voir_pedagogie = $role !== 'INTENDANT';
$is_admin = $peut_voir_finances;

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? '';

// ── Scolarité ─────────────────────────────────────────────────────────
$nb_eleves  = $id_annee ? (int) db_val("SELECT COUNT(DISTINCT id_eleve) FROM inscription WHERE id_annee=?", [$id_annee]) : 0;
$nb_garcons = $id_annee ? (int) db_val(
    "SELECT COUNT(DISTINCT i.id_eleve) FROM inscription i JOIN eleve e ON e.id=i.id_eleve
     WHERE i.id_annee=? AND e.sexe='M'", [$id_annee]
) : 0;
$nb_filles = $id_annee ? (int) db_val(
    "SELECT COUNT(DISTINCT i.id_eleve) FROM inscription i JOIN eleve e ON e.id=i.id_eleve
     WHERE i.id_annee=? AND e.sexe='F'", [$id_annee]
) : 0;
$nb_classes     = (int) db_val("SELECT COUNT(*) FROM classe WHERE archivee=0");
$nb_enseignants = (int) db_val("SELECT COUNT(*) FROM enseignant");

$par_classe = $id_annee ? db_all(
    "SELECT c.id, c.designation, c.code_niveau, n.ordre_niveau,
            COUNT(DISTINCT i.id_eleve) AS effectif,
            SUM(e.sexe='M') AS nb_m, SUM(e.sexe='F') AS nb_f
     FROM classe c
     LEFT JOIN niveau n ON n.code_niveau=c.code_niveau
     LEFT JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
     LEFT JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
     WHERE c.archivee=0
     GROUP BY c.id ORDER BY n.ordre_niveau, c.designation",
    [$id_annee]
) : [];

// ── Pédagogie : moyenne ARITHMÉTIQUE SIMPLE des notes de compétence de la
// séquence active (PAS la formule officielle du bulletin — coefficients par
// compétence/matière, voir mat_avg_comp() dans secondaire/pages/bulletins/
// pdf.php) : approximation volontairement simple, suffisante pour un repère
// "à première vue". "Tableau d'honneur" simplifié de même (seuil de moyenne
// seul, sans le sous-critère heures d'absence — get_reglage_mention_bulletin()).
$moy_generale_ecole = null; $taux_reussite_ecole = 0; $nb_evalues_ecole = 0; $palmares = [];
$nb_tableau_honneur = 0; $meilleure_classe = null; $par_niveau_moy = []; $nb_admis_ecole = 0;
if ($peut_voir_pedagogie && $id_annee) {
    $moyennes_eleve = db_all(
        "SELECT n.id_eleve, AVG(n.valeur) AS moy, c.id AS id_classe, c.designation, c.code_niveau,
                e.nom, e.prenom
         FROM note n
         JOIN sequence s ON s.id = n.id_seq
         JOIN trimestre t ON t.id = s.id_trim AND t.active = 1
         JOIN inscription i ON i.id_eleve = n.id_eleve AND i.id_annee = ?
         JOIN classe c ON c.id = i.id_classe
         JOIN eleve e ON e.id = n.id_eleve
         WHERE n.valeur IS NOT NULL
         GROUP BY n.id_eleve, c.id, c.designation, c.code_niveau, e.nom, e.prenom",
        [$id_annee]
    );
    $nb_evalues_ecole = count($moyennes_eleve);
    if ($nb_evalues_ecole > 0) {
        $reglage_mention = get_reglage_mention_bulletin($id_annee);
        $somme = 0.0; $niveau_somme = []; $niveau_nb = []; $classe_somme = []; $classe_nb = []; $classe_nom = [];
        foreach ($moyennes_eleve as $m) {
            $moy = (float) $m['moy'];
            $somme += $moy;
            if ($moy >= 10) $nb_admis_ecole++;
            if ($moy >= $reglage_mention['moy_tableau_honneur']) $nb_tableau_honneur++;
            $niveau_somme[$m['code_niveau']] = ($niveau_somme[$m['code_niveau']] ?? 0) + $moy;
            $niveau_nb[$m['code_niveau']]    = ($niveau_nb[$m['code_niveau']] ?? 0) + 1;
            $classe_somme[$m['id_classe']] = ($classe_somme[$m['id_classe']] ?? 0) + $moy;
            $classe_nb[$m['id_classe']]    = ($classe_nb[$m['id_classe']] ?? 0) + 1;
            $classe_nom[$m['id_classe']]   = $m['designation'];
        }
        $moy_generale_ecole  = round($somme / $nb_evalues_ecole, 2);
        $taux_reussite_ecole = round($nb_admis_ecole / $nb_evalues_ecole * 100, 1);
        foreach ($niveau_nb as $niv => $nb) {
            $par_niveau_moy[] = ['niveau' => $niv, 'moy' => round($niveau_somme[$niv] / $nb, 2)];
        }
        foreach ($classe_nb as $idc => $nb) {
            $moy_c = $classe_somme[$idc] / $nb;
            if ($meilleure_classe === null || $moy_c > $meilleure_classe['moy']) {
                $meilleure_classe = ['nom' => $classe_nom[$idc], 'moy' => $moy_c];
            }
        }
        usort($moyennes_eleve, fn($a, $b) => $b['moy'] <=> $a['moy']);
        $palmares = array_slice($moyennes_eleve, 0, 5);
    }
}

// ── Finances (Situation par élève + Top impayés + widgets) — statut
// public/privé (etablissement.statut, migration v4 secondaire) : jamais les
// deux modules Paiements à la fois (voir layout/menu_secondaire.php), donc
// jamais les deux sources de données à la fois non plus.
$total_du = 0.0; $total_paye = 0.0; $taux_recouvrement = 0;
$nb_payes_integral = 0; $nb_avance = 0; $montant_avance = 0.0;
$nb_partiel = 0; $montant_solde_partiel = 0.0;
$nb_insolvables = 0; $montant_insolvables = 0.0;
$finance_par_classe = []; $tous_soldes = [];
if ($peut_voir_finances && $id_annee) {
    if ($statut_ecole === 'prive') {
        $eleves_finance = prive_finances_du_par_eleve($id_annee);
        $paye_par_eleve = [];
        foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_annee=? GROUP BY id_eleve", [$id_annee]) as $r) {
            $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
        }
    } else {
        $classes_effectif = db_all(
            "SELECT c.id AS id_classe, c.designation, c.code_niveau, COUNT(DISTINCT e.id) AS effectif
             FROM classe c
             JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
             JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
             WHERE c.archivee=0 GROUP BY c.id", [$id_annee]
        );
        $montant_du_par_niveau = [];
        $eleves_finance = db_all(
            "SELECT e.id, c.id AS id_classe, c.designation, c.code_niveau, e.matricule, e.nom, e.prenom
             FROM eleve e JOIN inscription i ON i.id_eleve=e.id JOIN classe c ON c.id=i.id_classe
             WHERE e.statut='actif' AND i.id_annee=? ORDER BY e.nom, e.prenom", [$id_annee]
        );
        foreach ($eleves_finance as &$e) {
            if (!isset($montant_du_par_niveau[$e['code_niveau']])) {
                $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau=?", [$e['code_niveau']]);
                $montant_du_par_niveau[$e['code_niveau']] = (float) db_val(
                    "SELECT COALESCE(SUM(montant),0) FROM obligation_frais WHERE id_annee=? AND actif=1
                     AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))",
                    [$id_annee, $id_cycle, $e['code_niveau']]
                );
            }
            $e['du'] = $montant_du_par_niveau[$e['code_niveau']];
        }
        unset($e);
        $paye_par_eleve = [];
        foreach (db_all("SELECT id_eleve, SUM(montant) AS paye FROM paiement_frais WHERE id_annee=? GROUP BY id_eleve", [$id_annee]) as $r) {
            $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
        }
    }
    foreach ($eleves_finance as $e) {
        $du   = $e['du'];
        $paye = $paye_par_eleve[(int) $e['id']] ?? 0.0;
        $total_du   += $du;
        $total_paye += $paye;

        $ecart = $paye - $du;
        if ($ecart > 0.009)                              { $nb_avance++;      $montant_avance        += $ecart; }
        elseif (abs($ecart) <= 0.009 && $du > 0.009)      { $nb_payes_integral++; }
        elseif ($paye <= 0.009)                           { $nb_insolvables++; $montant_insolvables   += $du; }
        else                                                { $nb_partiel++;     $montant_solde_partiel += ($du - $paye); }

        $idc = (int) $e['id_classe'];
        $finance_par_classe[$idc] ??= ['du' => 0.0, 'paye' => 0.0];
        $finance_par_classe[$idc]['du']   += $du;
        $finance_par_classe[$idc]['paye'] += $paye;

        $solde = $du - $paye;
        if ($solde > 0.009) {
            $tous_soldes[] = ['id_eleve' => (int) $e['id'], 'nom' => $e['nom'], 'prenom' => $e['prenom'],
                'mat' => $e['matricule'] ?? '', 'classe' => $e['designation'], 'solde' => $solde];
        }
    }
    usort($tous_soldes, fn($a, $b) => $b['solde'] <=> $a['solde']);
    $taux_recouvrement = $total_du > 0 ? round($total_paye / $total_du * 100, 1) : 0;
}
$top_impayes = array_slice($tous_soldes, 0, 10);

$chart_recouvrement_classe = [];
if ($peut_voir_finances) {
    foreach ($par_classe as $c) {
        $fc = $finance_par_classe[(int) $c['id']] ?? ['du' => 0.0, 'paye' => 0.0];
        if ($fc['du'] <= 0.009) continue;
        $chart_recouvrement_classe[] = ['nom' => $c['designation'], 'taux' => round($fc['paye'] / $fc['du'] * 100, 1)];
    }
}

// ── Dépenses + solde de caisse — PRIVÉ uniquement (depense_privee n'existe
// que pour ce statut, voir PAIEMENT PRIVÉ, layout/menu_secondaire.php ; une
// école PUBLIQUE n'a pas d'équivalent "dépenses" dans ce schéma).
$total_depenses = 0.0; $solde_caisse_val = 0.0; $depenses_par_categorie = [];
$encaissements_par_mois = [];
if ($peut_voir_finances && $id_annee) {
    if ($statut_ecole === 'prive') {
        $total_depenses   = (float) db_val("SELECT COALESCE(SUM(montant),0) FROM depense_privee WHERE id_annee=?", [$id_annee]);
        $solde_caisse_val = prive_solde_caisse($id_annee);
        $depenses_par_categorie = db_all(
            "SELECT c.libelle, SUM(d.montant) AS total FROM depense_privee d
             JOIN categorie_depense_privee c ON c.id=d.id_categorie
             WHERE d.id_annee=? GROUP BY c.id ORDER BY total DESC", [$id_annee]
        );
        $encaissements_par_mois = db_all(
            "SELECT DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant_paiement) AS total
             FROM paiement_prive WHERE id_annee=? GROUP BY mois ORDER BY mois", [$id_annee]
        );
    } else {
        $solde_caisse_val = $total_paye;
        $encaissements_par_mois = db_all(
            "SELECT DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant) AS total
             FROM paiement_frais WHERE id_annee=? GROUP BY mois ORDER BY mois", [$id_annee]
        );
    }
}

// ── Paie / RH — dernière période créée (tables partagées avec le primaire,
// paie_fonctions.php réutilisé TEL QUEL côté secondaire, voir
// bd/assoc/schema_ref_ecole_secondaire.sql). Ces tables (grade_enseignant,
// periode_paie, bulletin_paie, avance_salaire…) sont très récentes : les
// écoles secondaires créées avant elles ne les ont pas encore (aucune
// migration de portage pour l'instant, hors scope ici) — try/catch pour ne
// jamais planter le tableau de bord tant que ce n'est pas fait, le widget
// Paie se réduit alors à "Aucune période de paie créée" (bug réel constaté
// le 22/09/2026 sur les 2 écoles secondaires existantes).
require_once __DIR__ . '/../paie_fonctions.php';
$derniere_periode = null; $avances_encours = 0.0;
if ($peut_voir_paie) {
  try {
    $derniere_periode = db_one(
        "SELECT p.*, COUNT(b.id) AS nb_bulletins, COALESCE(SUM(b.net_a_payer),0) AS total_net,
                SUM(CASE WHEN b.statut='Payé' THEN 1 ELSE 0 END) AS nb_payes
         FROM periode_paie p LEFT JOIN bulletin_paie b ON b.id_periode=p.id
         GROUP BY p.id ORDER BY p.annee DESC, p.mois DESC LIMIT 1"
    );
    foreach (db_all("SELECT id FROM avance_salaire") as $a) {
        $avances_encours += solde_avance((int) $a['id']);
    }
  } catch (\Throwable $e) {
    $derniere_periode = null; $avances_encours = 0.0;
  }
}
$taux_bulletins_payes = ($derniere_periode && (int) $derniere_periode['nb_bulletins'] > 0)
    ? round((int) $derniere_periode['nb_payes'] / (int) $derniere_periode['nb_bulletins'] * 100, 1) : 0;

$fmt_moy = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$fmt_f   = fn(float $v): string => number_format($v, 0, ',', ' ') . ' F';

$titre_page = 'Tableau de bord';
require_once __DIR__ . '/../layout/header.php';
?>
<style>
/* ── Copié à l'identique de dashboard.php (primaire) — ne pas laisser
   diverger sans re-synchroniser les deux fichiers. ── */
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

.pedago-hero{display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap;background:linear-gradient(120deg,var(--primary) 0%,var(--primary-2) 100%);border-radius:var(--radius);padding:1rem 1.2rem;color:#fff}
.gauge-wrap-lg{width:84px;height:84px}
.gauge-val-lg{font-size:1.15rem;color:#fff;flex-direction:column;line-height:1.05}
.gauge-val-lg small{font-size:.55rem;font-weight:600;opacity:.8;text-transform:uppercase;letter-spacing:.03em}
.pedago-hero-moy-wrap{padding-right:1rem;border-right:1px solid rgba(255,255,255,.22)}
.pedago-hero-moy{font-size:2.3rem;font-weight:800;font-family:var(--font-heading);color:var(--gold-light);line-height:1}
.pedago-hero-moy span{font-size:1.05rem;opacity:.75;font-weight:600}
.pedago-hero-lbl{font-size:.68rem;opacity:.82;max-width:190px}
.pedago-chips{display:flex;flex-wrap:wrap;gap:.5rem;flex:1}
.pedago-chip{display:flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);border-radius:8px;padding:.4rem .65rem;min-width:110px}
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
  <div class="sub">Année scolaire <?= h($val_annee) ?></div>
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
      <?php if ($statut_ecole === 'prive'): ?>
      <div class="hn"><b style="color:#ffcf8a"><?= $fmt_f($total_depenses) ?></b><span>Dépensé</span></div>
      <?php endif; ?>
      <div class="hn"><b style="color:<?= $solde_caisse_val >= 0 ? '#7fe0ab' : '#ff9d9d' ?>"><?= $fmt_f($solde_caisse_val) ?></b><span>Solde de caisse</span></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($peut_voir_pedagogie): ?>
  <div class="hero-divider"></div>
  <div class="hero-zone">
    <div class="hero-zone-head"><i class="bi bi-mortarboard-fill"></i>Pédagogie</div>
    <div class="hero-zone-nums">
      <?php if ($nb_evalues_ecole > 0): ?>
      <div class="gauge-wrap gauge-wrap-sm"><canvas id="chartReussite"></canvas><div class="gauge-val gauge-val-sm"><?= $taux_reussite_ecole ?>%</div></div>
      <div class="hn"><b><?= $fmt_moy($moy_generale_ecole) ?>/20</b><span>Moyenne</span></div>
      <div class="hn"><b><?= $nb_evalues_ecole ?></b><span>Évalués</span></div>
      <?php else: ?>
      <div class="hn" style="font-size:.75rem;opacity:.85">Aucune note saisie</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($peut_voir_finances): ?>
<!-- ── 1. Situation financière des élèves ── -->
<div class="row g-2 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-people-fill me-1" style="color:var(--ok)"></i>Situation financière des élèves</span>
        <a href="<?= APP_URL ?>/secondaire/pages/<?= $statut_ecole === 'prive' ? 'paiements_prives/impayes.php' : 'paiements/rapport.php?onglet=insolvables' ?>" class="btn btn-sm btn-abz-outline" style="font-size:.7rem">Impayés <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
      <div class="card-body">
        <div class="pedago-chips">
          <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)">
            <i class="bi bi-check-circle text-success"></i>
            <div><b><?= $nb_payes_integral ?></b><span>Payés (intégral)</span></div>
          </div>
          <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)">
            <i class="bi bi-graph-up-arrow" style="color:#0d6efd"></i>
            <div><b><?= $nb_avance ?></b><span>En avance<?= $nb_avance > 0 ? ' · +' . $fmt_f($montant_avance) : '' ?></span></div>
          </div>
          <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)">
            <i class="bi bi-hourglass-split" style="color:var(--warn)"></i>
            <div><b><?= $nb_partiel ?></b><span>Solde partiel<?= $nb_partiel > 0 ? ' · ' . $fmt_f($montant_solde_partiel) : '' ?></span></div>
          </div>
          <div class="pedago-chip" style="background:var(--hover-bg);border-color:var(--border)">
            <i class="bi bi-x-octagon text-danger"></i>
            <div><b><?= $nb_insolvables ?></b><span>Insolvables (rien payé)<?= $nb_insolvables > 0 ? ' · ' . $fmt_f($montant_insolvables) : '' ?></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($top_impayes): ?>
<!-- ── Top élèves à relancer ── -->
<div class="row g-2 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-exclamation-diamond me-1" style="color:var(--danger)"></i>Top élèves à relancer</span>
        <a href="<?= APP_URL ?>/secondaire/pages/<?= $statut_ecole === 'prive' ? 'paiements_prives/impayes.php' : 'paiements/rapport.php?onglet=insolvables' ?>" class="btn btn-sm btn-abz-outline" style="font-size:.7rem">Voir tous les impayés <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
          <thead><tr><th>#</th><th>Élève</th><th>Matricule</th><th>Classe</th><th class="text-end">Solde dû</th></tr></thead>
          <tbody>
            <?php foreach ($top_impayes as $i => $e): ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td class="fw-semibold"><?= h(mb_strtoupper($e['nom'])) ?> <?= h($e['prenom'] ?? '') ?></td>
              <td class="text-muted"><?= h($e['mat']) ?></td>
              <td><?= h($e['classe']) ?></td>
              <td class="text-end fw-bold text-danger"><?= $fmt_f($e['solde']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── 2. Paiements et dépenses ── -->
<div class="row g-2 mb-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-cash-coin me-1" style="color:var(--ok)"></i>Finances</span>
        <a href="<?= APP_URL ?>/secondaire/pages/<?= $statut_ecole === 'prive' ? 'paiements_prives/statistiques.php' : 'paiements/rapport.php?onglet=stats' ?>" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="gauge-wrap"><canvas id="chartRecouvrement"></canvas><div class="gauge-val"><?= $taux_recouvrement ?>%</div></div>
          <div class="flex-grow-1 row g-1">
            <div class="col-6 mini-stat"><div class="v"><?= $fmt_f($total_du) ?></div><div class="l">Total dû</div></div>
            <div class="col-6 mini-stat"><div class="v text-success"><?= $fmt_f($total_paye) ?></div><div class="l">Encaissé</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($statut_ecole === 'prive'): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-cart-dash me-1" style="color:var(--warn)"></i>Dépenses</span>
        <a href="<?= APP_URL ?>/secondaire/pages/depenses_privees/journal.php" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
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
  <?php endif; ?>

  <?php if ($peut_voir_paie): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-wallet2 me-1" style="color:var(--purple)"></i>Paie</span>
        <a href="<?= APP_URL ?>/secondaire/pages/paie/index.php" class="btn btn-sm btn-abz-outline" style="font-size:.7rem"><i class="bi bi-arrow-right"></i></a>
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
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($peut_voir_finances && ($depenses_par_categorie || $encaissements_par_mois)): ?>
<div class="row g-2 mb-3">
  <?php if ($depenses_par_categorie): ?>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-pie-chart me-1" style="color:var(--warn)"></i>Dépenses par catégorie</div>
      <div class="card-body">
        <div style="height:200px"><canvas id="chartDepensesCategorie"></canvas></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($encaissements_par_mois): ?>
  <div class="<?= $depenses_par_categorie ? 'col-lg-7' : 'col-12' ?>">
    <div class="card h-100">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-graph-up-arrow me-1" style="color:var(--ok)"></i>Évolution des encaissements</div>
      <div class="card-body">
        <div style="height:200px"><canvas id="chartEncaissementsMois"></canvas></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($peut_voir_pedagogie): ?>
<!-- ── 3. Pédagogie (détail) ── -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-mortarboard me-1" style="color:var(--info)"></i>Pédagogie — détail</span>
    <a href="<?= APP_URL ?>/secondaire/pages/statistiques/index.php" class="btn btn-sm btn-abz-outline" style="font-size:.72rem">Statistiques <i class="bi bi-arrow-right ms-1"></i></a>
  </div>
  <div class="card-body">
    <?php if ($nb_evalues_ecole === 0): ?>
      <div class="text-muted text-center py-2" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Aucune note saisie pour la séquence en cours.</div>
    <?php else: ?>

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
      <?php if ($par_niveau_moy): ?>
      <div class="col-lg-5">
        <div class="pedago-subcard h-100">
          <div class="pedago-subcard-title"><i class="bi bi-diagram-3 me-1"></i>Moyenne par niveau</div>
          <div style="height:<?= max(90, count($par_niveau_moy) * 30) ?>px"><canvas id="chartNiveaux"></canvas></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="<?= $par_niveau_moy ? 'col-lg-7' : 'col-12' ?>">
        <div class="pedago-subcard h-100">
          <div class="pedago-subcard-title"><i class="bi bi-trophy me-1" style="color:var(--gold)"></i>Meilleurs élèves de l'établissement</div>
          <?php if ($palmares): ?>
            <?php foreach ($palmares as $i => $p): $rg = $i + 1;
              $couleur = $rg === 1 ? '#c8960a' : ($rg === 2 ? '#9ca3af' : ($rg === 3 ? '#b06a35' : '#c7d8f0'));
              $moy_p = (float) $p['moy'];
              $init = mb_strtoupper(mb_substr($p['nom'], 0, 1) . mb_substr($p['prenom'] ?? '', 0, 1));
            ?>
            <div class="leaderboard-row">
              <div class="leaderboard-rang" style="background:<?= $couleur ?>"><?= $rg <= 3 ? '<i class="bi bi-award-fill"></i>' : $rg ?></div>
              <div class="leaderboard-avatar"><?= h($init) ?></div>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-truncate"><?= h(mb_strtoupper($p['nom']) . ' ' . ($p['prenom'] ?? '')) ?></div>
                <div class="text-muted" style="font-size:.68rem"><?= h($p['designation']) ?></div>
              </div>
              <div class="text-end" style="min-width:70px">
                <div class="fw-bold text-success"><?= $fmt_moy($moy_p) ?>/20</div>
                <div class="progress" style="height:4px"><div class="progress-bar bg-success" style="width:<?= min(100, $moy_p / 20 * 100) ?>%"></div></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-muted text-center py-2" style="font-size:.8rem">Aucune moyenne calculée pour cette séquence.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── 4. Scolarité : effectifs par classe ── -->
<div class="row g-2 mt-3">
  <div class="<?= $peut_voir_finances ? 'col-12' : 'col-lg-8' ?>">
    <div class="card">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-bar-chart-line me-1"></i>Effectifs<?= $peut_voir_finances ? ' & finances' : '' ?> par classe</div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
          <thead>
            <tr>
              <th>Classe</th><th class="text-center">G</th><th class="text-center">F</th><th class="text-center fw-bold">Total</th>
              <?php if ($peut_voir_finances): ?>
              <th class="text-end">Dû</th><th class="text-end">Encaissé</th><th class="text-end">Reste</th>
              <th class="text-center">Recouvr.</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($par_classe as $c):
              $fc = $finance_par_classe[(int) $c['id']] ?? ['du' => 0.0, 'paye' => 0.0];
              $reste_c = max(0.0, $fc['du'] - $fc['paye']);
              $taux_c  = $fc['du'] > 0 ? round($fc['paye'] / $fc['du'] * 100, 1) : 0;
            ?>
            <tr>
              <td class="fw-semibold"><?= h($c['designation']) ?></td>
              <td class="text-center"><span class="badge-m"><?= (int) $c['nb_m'] ?></span></td>
              <td class="text-center"><span class="badge-f"><?= (int) $c['nb_f'] ?></span></td>
              <td class="text-center fw-bold"><?= (int) $c['effectif'] ?></td>
              <?php if ($peut_voir_finances): ?>
              <td class="text-end"><?= $fmt_f($fc['du']) ?></td>
              <td class="text-end text-success"><?= $fmt_f($fc['paye']) ?></td>
              <td class="text-end <?= $reste_c > 0.009 ? 'text-danger' : '' ?>"><?= $fmt_f($reste_c) ?></td>
              <td class="text-center">
                <span class="fw-bold" style="color:<?= $taux_c >= 80 ? '#1e7c50' : ($taux_c >= 50 ? '#c8960a' : '#dc3545') ?>"><?= $taux_c ?>%</span>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($peut_voir_finances): ?>
          <tfoot>
            <tr class="fw-bold" style="background:var(--hover-bg)">
              <td>Total</td>
              <td class="text-center"><?= $nb_garcons ?></td>
              <td class="text-center"><?= $nb_filles ?></td>
              <td class="text-center"><?= $nb_eleves ?></td>
              <td class="text-end"><?= $fmt_f($total_du) ?></td>
              <td class="text-end text-success"><?= $fmt_f($total_paye) ?></td>
              <td class="text-end <?= ($total_du - $total_paye) > 0.009 ? 'text-danger' : '' ?>"><?= $fmt_f(max(0.0, $total_du - $total_paye)) ?></td>
              <td class="text-center"><?= $taux_recouvrement ?>%</td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-2 mt-2">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-person-badge me-1"></i>Personnel</div>
      <div class="card-body">
        <div class="d-flex justify-content-between py-1" style="font-size:.82rem">
          <span class="text-muted">Enseignant(e)s</span><span class="fw-bold"><?= $nb_enseignants ?></span>
        </div>
        <div class="d-flex justify-content-between py-1" style="font-size:.82rem">
          <span class="text-muted">Direction</span>
          <span class="fw-bold"><?= h(get_etablissement()['chef_etablissement'] ?? '—') ?></span>
        </div>
      </div>
    </div>
  </div>
  <?php if ($chart_recouvrement_classe): ?>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-graph-up me-1"></i>Taux de recouvrement par classe</div>
      <div class="card-body">
        <div style="height:<?= max(90, count($chart_recouvrement_classe) * 24) ?>px"><canvas id="chartRecouvrementClasse"></canvas></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /.dash-page -->

<script src="<?= APP_URL ?>/assets/vendor/chart/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Segoe UI','Inter',system-ui,sans-serif";
Chart.defaults.font.size = 11;

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
<?php if ($peut_voir_pedagogie && $nb_evalues_ecole > 0): ?>
gaugeDoughnut('chartReussite', <?= (float) $taux_reussite_ecole ?>, 50);
<?php endif; ?>

<?php if ($peut_voir_pedagogie && $par_niveau_moy): ?>
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

<?php if ($peut_voir_finances): ?>
gaugeDoughnut('chartRecouvrementTop', <?= (float) $taux_recouvrement ?>, 50);
gaugeDoughnut('chartRecouvrement', <?= (float) $taux_recouvrement ?>, 50);

<?php if ($chart_recouvrement_classe): ?>
new Chart(document.getElementById('chartRecouvrementClasse'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($c) => $c['nom'], $chart_recouvrement_classe)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($c) => $c['taux'], $chart_recouvrement_classe)) ?>,
            backgroundColor: <?= json_encode(array_map(
                fn($c) => $c['taux'] >= 80 ? '#1e7c50' : ($c['taux'] >= 50 ? '#c8960a' : '#dc3545'),
                $chart_recouvrement_classe
            )) ?>,
            borderRadius: 4, barThickness: 14,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.parsed.x + '% encaissé' } } },
        scales: {
            x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#e2ded0' } },
            y: { grid: { display: false } },
        },
    },
});
<?php endif; ?>

<?php if ($statut_ecole === 'prive'): ?>
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

<?php if ($depenses_par_categorie): ?>
new Chart(document.getElementById('chartDepensesCategorie'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($d) => $d['libelle'], $depenses_par_categorie)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($d) => (float) $d['total'], $depenses_par_categorie)) ?>,
            backgroundColor: ['#6b7280', '#c8960a', '#7c3aed', '#0d6efd', '#dc3545', '#1e7c50', '#0891b2', '#d97706'],
            borderWidth: 0,
        }]
    },
    options: {
        cutout: '55%', maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } },
            tooltip: { callbacks: { label: ctx => ctx.label + ' : ' + ctx.parsed.toLocaleString('fr-FR') + ' F' } },
        },
    },
});
<?php endif; ?>

<?php if ($encaissements_par_mois): ?>
new Chart(document.getElementById('chartEncaissementsMois'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($m) => $m['mois'], $encaissements_par_mois)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($m) => (float) $m['total'], $encaissements_par_mois)) ?>,
            borderColor: '#1e7c50', backgroundColor: 'rgba(30,124,80,.12)',
            fill: true, tension: 0.3, pointRadius: 3, pointBackgroundColor: '#1e7c50',
        }]
    },
    options: {
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.parsed.y.toLocaleString('fr-FR') + ' F' } },
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr-FR') + ' F' }, grid: { color: '#f2f0e8' } },
            x: { grid: { display: false } },
        },
    },
});
<?php endif; ?>
<?php endif; ?>

<?php if ($peut_voir_paie): ?>
gaugeDoughnut('chartBulletins', <?= (float) $taux_bulletins_payes ?>, 100);
<?php endif; ?>

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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
