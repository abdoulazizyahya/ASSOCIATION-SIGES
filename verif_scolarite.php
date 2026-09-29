<?php
// verif_scolarite.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un certificat de scolarité arrive ici pour
// vérifier son authenticité. Type-aware (primaire/secondaire) depuis le
// 26/09/2026 : partagée par pdf/certificat_scolarite.php (primaire) et
// secondaire/pdf/certificat_scolarite.php (même bibliothèque hash/URL,
// pdf/verif_scolarite_lib.php — voir son en-tête).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_scolarite_lib.php';
verif_exiger_ecole_publique();  // multi-école : URL sans &ec= -> page neutre

$id_eleve   = (int)($_GET['e'] ?? 0);
$h_recu     = (string)($_GET['h'] ?? '');
$secondaire = type_enseignement_courant() === 'secondaire';

// Colonnes propres à chaque schéma, résultat aliasé sur les MÊMES clés
// (Nom_elv/Prenom_elv) pour que le reste du fichier (gabarit HTML) reste
// inchangé — même convention que mot_de_passe_oublie.php/dashboard.php.
$eleve = $id_eleve
    ? ($secondaire
        ? db_one("SELECT *, nom AS Nom_elv, prenom AS Prenom_elv FROM eleve WHERE id=?", [$id_eleve])
        : db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]))
    : null;

$authentique = false;
if ($eleve && $h_recu !== '') {
    $authentique = scolarite_verif_hash_ok($id_eleve, id_affichage_eleve($eleve), $h_recu);
}

$classe = null;
if ($authentique) {
    $classe = $secondaire
        ? db_one(
            "SELECT c.designation AS designation, a.libelle AS annee
             FROM inscription i
             JOIN classe c ON c.id = i.id_classe
             JOIN annee_scolaire a ON a.id = i.id_annee
             WHERE i.id_eleve=? ORDER BY a.libelle DESC LIMIT 1", [$id_eleve])
        : db_one(
            "SELECT c.DesignationClasses AS designation, i.val_annee AS annee
             FROM inscrire i JOIN classe c ON c.IDClasses = i.IDClasses
             WHERE i.id_eleve=? ORDER BY i.val_annee DESC LIMIT 1", [$id_eleve]);
}

// URL du certificat réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir le certificat" (jamais automatiquement). Le jeton "vh" (=
// le hash déjà vérifié ci-dessus) permet à pdf/certificat_scolarite.php de
// servir ce certificat précis sans exiger de connexion.
// verif_ajout_ec() : en multi-établissement, propage &ec=CODE — sinon
// pdf/certificat_scolarite.php (accès public par « vh ») reste pointé sur
// l'annuaire.
// Générateur du TYPE de l'école : une école secondaire renvoyée vers
// pdf/certificat_scolarite.php (primaire) tombait sur la page « en
// construction » (garde primaire/secondaire de connexion.php).
$pdf_url = $authentique
    ? verif_ajout_ec(APP_URL . ($secondaire ? '/secondaire/pdf' : '/pdf') . '/certificat_scolarite.php?id=' . $id_eleve . '&vh=' . urlencode($h_recu))
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de certificat de scolarité — <?= h(APP_NOM) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    body { background:#0f1a3a; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:system-ui,sans-serif; }
    .verif-card { background:#fff; border-radius:16px; padding:2.2rem 1.8rem; max-width:420px; width:92%; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    .verif-icon { font-size:3.2rem; }
    .verif-card { position:relative; overflow:hidden; }
    .verif-card > * { position:relative; z-index:1; }
    /* Tampon oblique « AUTHENTIQUE » en fond (même tampon que sur le PDF). */
    .tampon { position:absolute !important; z-index:0 !important; top:50%; left:50%;
              transform:translate(-50%,-50%) rotate(-24deg); pointer-events:none;
              border:5px double rgba(0,128,64,.30); border-radius:10px; padding:.3rem 1.1rem;
              color:rgba(0,128,64,.24); font:900 2.6rem/1.1 system-ui,sans-serif; letter-spacing:.12em;
              text-align:center; white-space:nowrap; }
    .tampon small { display:block; font-size:.72rem; font-weight:700; letter-spacing:.04em; }
  </style>
</head>
<body>
  <div class="verif-card">
    <?php if ($authentique): ?>
      <div class="tampon" aria-hidden="true">AUTHENTIQUE<small>Vérifié le <?= date('d/m/Y à H:i') ?></small></div>
      <div class="verif-icon text-success"><i class="bi bi-patch-check-fill"></i></div>
      <h4 class="mt-2 mb-1 text-success">Certificat de scolarité authentique</h4>
      <p class="text-muted mb-0">
        <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        NIU : <?= h(id_affichage_eleve($eleve)) ?><br>
        <?php if ($classe): ?>
          Classe : <?= h($classe['designation']) ?><br>
          Année scolaire : <?= h($classe['annee']) ?>
        <?php endif; ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm mt-2">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le certificat
      </a>
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
