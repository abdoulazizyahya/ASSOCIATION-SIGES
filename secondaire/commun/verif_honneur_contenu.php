<?php
// secondaire/commun/verif_honneur_contenu.php — vérification publique d'un
// certificat de tableau d'honneur d'école SECONDAIRE (QR imprimé par
// secondaire/commun/pages/tableau_honneur/pdf_certificat.php). Inclus par
// verif/honneur.php (racine) une fois l'école résolue et reconnue comme
// secondaire — même principe que verif_carte_contenu.php (08/11/2026 :
// jusqu'ici verif/honneur.php ne connaissait que le primaire).
// Paramètres : e = id élève, v = trim|seq|annee, p = id de la période,
// m = modèle (présentation), h = signature.
require_once __DIR__ . '/pdf/verif_honneur_lib.php';

$id_eleve   = (int) ($_GET['e'] ?? 0);
$vue        = in_array($_GET['v'] ?? '', ['trim', 'seq', 'annee'], true) ? $_GET['v'] : '';
$id_periode = (int) ($_GET['p'] ?? 0);
$modele     = in_array((int) ($_GET['m'] ?? 1), [1, 2, 3], true) ? (int) ($_GET['m'] ?? 1) : 1;
$h_recu     = (string) ($_GET['h'] ?? '');

try { $eleve = honneur_sec_verif_valider($id_eleve, $vue, $id_periode, $h_recu); } catch (\Throwable $e) { $eleve = null; }
$authentique = $eleve !== null;
$etab = $authentique ? get_etablissement() : [];

// Période et classe du certificat (le QR ne porte pas la classe : on prend
// l'inscription de l'élève l'année de la période).
$periode = ''; $id_annee = 0; $classe = null;
if ($authentique) {
    try {
        if ($vue === 'trim') {
            $t = db_one("SELECT libelle, id_annee FROM trimestre WHERE id=?", [$id_periode]);
            $periode = (string) ($t['libelle'] ?? ''); $id_annee = (int) ($t['id_annee'] ?? 0);
        } elseif ($vue === 'seq') {
            $s = db_one("SELECT s.libelle, t.id_annee FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id=?", [$id_periode]);
            $periode = (string) ($s['libelle'] ?? ''); $id_annee = (int) ($s['id_annee'] ?? 0);
        } else {
            $id_annee = $id_periode;
        }
        $annee = $id_annee ? (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]) : '';
        if ($vue === 'annee') $periode = 'Année scolaire ' . $annee;
        $classe = $id_annee ? db_one(
            "SELECT c.id, c.designation FROM inscription i JOIN classe c ON c.id=i.id_classe WHERE i.id_eleve=? AND i.id_annee=? LIMIT 1",
            [$id_eleve, $id_annee]
        ) : null;
    } catch (\Throwable $e) { $classe = null; }
}

// Certificat réel, ouvert seulement sur clic : le jeton vh (= signature déjà
// vérifiée) donne l'accès public à CE certificat (voir pdf_certificat.php).
$pdf_url = ($authentique && $classe)
    ? verif_ajout_ec(APP_URL . '/secondaire/commun/pages/tableau_honneur/pdf_certificat.php?classe=' . (int) $classe['id']
        . ($vue === 'trim' ? '&trim=' . $id_periode : ($vue === 'seq' ? '&seq=' . $id_periode : '&vue=annee'))
        . '&annee=' . $id_annee . '&eleve=' . $id_eleve . '&modele=' . $modele . '&vh=' . urlencode($h_recu))
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de tableau d'honneur — <?= h(APP_NOM) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    body { background:#0f1a3a; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:system-ui,sans-serif; }
    .verif-card { background:#fff; border-radius:16px; padding:2.2rem 1.8rem; max-width:420px; width:92%; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    .verif-icon { font-size:3.2rem; }
  </style>
</head>
<body>
  <div class="verif-card">
    <?php if ($authentique): ?>
      <div class="verif-icon text-success"><i class="bi bi-patch-check-fill"></i></div>
      <h4 class="mt-2 mb-1 text-success">Tableau d'honneur authentique</h4>
      <p class="text-muted mb-3">
        <?php if (!empty($etab['nom_fr'])): ?><strong><?= h($etab['nom_fr']) ?></strong><br><?php endif; ?>
        <strong><?= h(mb_strtoupper((string) $eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></strong><br>
        <?= ($id_html = verif_identifiant_eleve_html($eleve)) !== '' ? $id_html . '<br>' : '' ?>
        <?php if ($classe): ?>Classe : <?= h($classe['designation']) ?><br><?php endif; ?>
        <?php if ($periode !== ''): ?><?= h($periode) ?><?php endif; ?>
      </p>
      <?php if ($pdf_url !== ''): ?>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-award me-1"></i>Ouvrir le certificat
      </a>
      <?php endif; ?>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce certificat n'a pas pu être authentifié auprès de <?= h(APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
