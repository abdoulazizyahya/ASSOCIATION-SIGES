<?php
// verif_bulletin.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un bulletin arrive ici pour vérifier son
// authenticité.
//
// 🐛 CORRIGÉ (repris tel quel d'ABZ_MBE, jamais adapté au schéma
// jaynitaare_v2 jusqu'ici — la vérification échouait donc TOUJOURS) :
// `eleve.id` → `eleve.id_eleve`, `$eleve['nom']/['prenom']` →
// `Nom_elv`/`Prenom_elv`, et l'URL du bulletin pointait vers
// `pages/bulletins/pdf*.php` (chemins ABZ_MBE) au lieu de
// `pdf/bulletin_annuel.php`/`pdf/bulletin_trimestriel.php` (les vrais
// fichiers ici, FR et AR). Ajout du paramètre `t` (piste fr/ar, absent du
// modèle ABZ_MBE à une seule piste — voir pdf/verif_lib.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_commun.php';

// QR de bulletin SECONDAIRE imprimé avant le 03/10/2026 : il ne porte pas
// &ec=. On retrouve son école en recalculant la signature dans chaque école
// secondaire (élève + période + matricule) : une seule correspond, on
// relance alors la page avec le bon &ec=. Les QR du primaire portent déjà
// le code d'école en multi-établissement.
if (empty($_GET['ec']) && verif_multi_ecoles() && in_array($_GET['v'] ?? '', ['trim', 'seq'], true)) {
    $e_q = (int) ($_GET['e'] ?? 0); $p_q = (int) ($_GET['p'] ?? 0); $h_q = (string) ($_GET['h'] ?? '');
    $secret = defined('BULLETIN_VERIF_SECRET') ? BULLETIN_VERIF_SECRET : 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET';
    if ($e_q > 0 && $p_q > 0 && $h_q !== '') {
        foreach (assoc_all("SELECT id, code FROM etablissement WHERE actif=1 AND type_enseignement='secondaire'") as $ec_cand) {
            try {
                $mat = avec_ecole((int) $ec_cand['id'], fn($l) => ecole_one($l, "SELECT matricule FROM eleve WHERE id=?", [$e_q]))['matricule'] ?? null;
            } catch (\Throwable $x) { $mat = null; }
            if ($mat === null) continue;
            $attendu = substr(hash_hmac('sha256', $e_q . '|' . $_GET['v'] . '|' . $p_q . '|' . $mat, $secret), 0, 20);
            if (hash_equals($attendu, $h_q)) {
                header('Location: ' . APP_URL . '/verif_bulletin.php?' . http_build_query($_GET + ['ec' => $ec_cand['code']]));
                exit;
            }
        }
    }
}
verif_exiger_ecole_publique();  // multi-école : URL sans &ec= -> page neutre

// École SECONDAIRE : tables et générateur de bulletin distincts du primaire.
if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
    require __DIR__ . '/secondaire/verif_bulletin_contenu.php';
    exit;
}
require_once __DIR__ . '/pdf/verif_lib.php';

$id_eleve   = (int) ($_GET['e'] ?? 0);
$vue_recue  = (string) ($_GET['v'] ?? '');
$vue        = in_array($vue_recue, ['trim', 'annee'], true) ? $vue_recue : 'annee';
$id_periode = (int) ($_GET['p'] ?? 0);
$piste      = ($_GET['t'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
$h_recu     = (string) ($_GET['h'] ?? '');
$chiffres_ar = ($_GET['chiffres_ar'] ?? '0') === '1'; // préférence d'affichage, pas signée — voir pdf/verif_lib.php::bulletin_verif_url().

$eleve       = bulletin_verif_valider($id_eleve, $vue, $id_periode, $piste, $h_recu);
$authentique = $eleve !== null;

// Bulletin FR d'une classe de section anglophone : réouvre
// pdf/bulletin_{trimestriel,annuel}_anglais.php (demande du 26/08/2026),
// jamais les fichiers français — même piste de données 'fr' (voir plus
// haut). Section résolue sur l'inscription de l'ANNÉE DU BULLETIN consulté
// (pas juste la plus récente : un élève promu depuis a une inscription plus
// récente dans une autre classe/section, qui ne concerne pas CE bulletin —
// bug trouvé en testant ce correctif). $id_periode = id_trim (vue 'trim',
// trimestre.id_annee = val_annee) ou l'entier dérivé de val_annee (vue
// 'annee', voir pdf/bulletin_annuel.php::$id_annee — reconstruit ici via
// annee_scolaire.val_annee LIKE '{id_annee}/%').
$anglais = false;
if ($authentique && $piste === 'fr') {
    $val_annee_periode = $vue === 'trim'
        ? (string) (db_val("SELECT id_annee FROM trimestre WHERE id_trim=?", [$id_periode]) ?? '')
        : (string) (db_val("SELECT val_annee FROM annee_scolaire WHERE val_annee LIKE ?", [$id_periode . '/%']) ?? '');
    if ($val_annee_periode !== '') {
        $anglais = db_val(
            "SELECT n.Section FROM inscrire i
             JOIN classe c ON c.IDClasses = i.IDClasses
             JOIN niveau n ON n.LibelleNiveau = c.Niveau
             WHERE i.id_eleve = ? AND i.val_annee = ? LIMIT 1",
            [$id_eleve, $val_annee_periode]
        ) === 'An';
    }
}

// URL du bulletin réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir le bulletin" (jamais automatiquement). Le jeton "vh" (= le
// hash déjà vérifié ci-dessus) permet aux générateurs de bulletin
// (pdf/bulletin_{annuel,trimestriel}{,_arabe,_anglais}.php) de servir CE
// bulletin précis sans exiger de connexion — voir leur en-tête, même mécanisme.
$pdf_url = '';
if ($authentique) {
    $fichier = 'bulletin_' . ($vue === 'annee' ? 'annuel' : 'trimestriel') . ($piste === 'ar' ? '_arabe' : ($anglais ? '_anglais' : ''));
    // verif_ajout_ec() : en multi-établissement, propage &ec=CODE — sans lui
    // le générateur de bulletin (accès public par « vh ») reste pointé sur
    // l'annuaire et échoue (« table … n'existe pas »).
    $pdf_url = verif_ajout_ec(APP_URL . '/pdf/' . $fichier . '.php?id=' . $id_eleve
             . ($vue === 'trim' ? '&trim=' . $id_periode : '')
             . '&vh=' . urlencode($h_recu)
             . ($chiffres_ar ? '&chiffres_ar=1' : ''));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de bulletin — <?= h(APP_NOM) ?></title>
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
      <h4 class="mt-2 mb-1 text-success">Document authentique</h4>
      <p class="text-muted mb-3">
        Bulletin de <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        Matricule : <?= h(id_affichage_eleve($eleve)) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le bulletin
      </a>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce document n'a pas pu être authentifié auprès de <?= h(APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
