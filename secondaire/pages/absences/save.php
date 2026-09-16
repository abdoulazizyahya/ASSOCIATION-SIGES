<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN','PROVISEUR','CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;

if (!$is_admin && !$is_ens) {
    flash_set('erreur', 'Accès non autorisé.');
    rediriger('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('secondaire/pages/absences/index.php');
}

// CSRF
$csrf_ok = isset($_POST['csrf'], $_SESSION['csrf_absences']) && hash_equals($_SESSION['csrf_absences'], $_POST['csrf']);
if (!$csrf_ok) {
    flash_set('erreur', 'Jeton de sécurité invalide, veuillez réessayer.');
    rediriger('secondaire/pages/absences/index.php');
}

$id_classe  = (int)($_POST['id_classe']  ?? 0);
$id_matiere = (int)($_POST['id_matiere'] ?? 0);
$id_seq     = (int)($_POST['id_seq']     ?? 0);
$back       = $_POST['back'] ?? 'index.php';

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

// Défense en profondeur : l'écran ne propose plus de choix de séquence,
// on revérifie côté serveur que la séquence postée est bien l'active.
$seq_active_id = (int)db_val(
    "SELECT s.id FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.active=1 AND t.id_annee=? LIMIT 1",
    [$id_annee]
);
if ($id_seq !== $seq_active_id) {
    flash_set('erreur', 'La séquence active a changé, veuillez réessayer.');
    rediriger('secondaire/pages/absences/index.php');
}

if (!$id_classe || !$id_matiere || !$id_seq) {
    flash_set('erreur', 'Paramètres manquants.');
    rediriger('secondaire/pages/absences/index.php');
}

// ── Sécurité : la classe/matière doit être accessible au rôle courant ──
if ($is_admin) {
    $ok = db_val("SELECT 1 FROM discipline WHERE IDClasses=? AND id_mat=?", [$id_classe, $id_matiere]);
} else {
    $ok = db_val(
        "SELECT 1 FROM dispenser WHERE matricule_ens=? AND val_annee=? AND IDClasses=? AND id_mat=?",
        [$mat_ens, $val_annee, $id_classe, $id_matiere]
    );
}
if (!$ok) {
    flash_set('erreur', 'Accès non autorisé à cette classe/matière.');
    rediriger('secondaire/pages/absences/index.php');
}

// ── Élèves inscrits (pour ne traiter que ceux réellement de la classe) ──
$eleves_ids = array_column(
    db_all(
        "SELECT e.id FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    ),
    'id'
);

// Élèves ayant déjà une note pour cette matière/séquence : jamais justifiables
$notes_eleves = array_column(
    db_all("SELECT id_eleve FROM note WHERE id_matiere=? AND id_seq=?", [$id_matiere, $id_seq]),
    'id_eleve'
);

$justifie_post = $_POST['justifie'] ?? [];
$raison_post   = $_POST['raison']   ?? [];

$n_saved = 0;
foreach ($eleves_ids as $eid) {
    $eid = (int)$eid;
    if (in_array($eid, $notes_eleves)) continue; // sécurité : pas de justification si note saisie

    $justifie = isset($justifie_post[$eid]) ? 1 : 0;
    $raison   = trim((string)($raison_post[$eid] ?? ''));
    if ($raison === '') $raison = null;

    $deja = db_val("SELECT 1 FROM absence_justifiee WHERE id_eleve=? AND id_matiere=? AND id_seq=?", [$eid, $id_matiere, $id_seq]);

    if (!$justifie && $raison === null && !$deja) {
        continue; // rien à faire, rien n'existait
    }

    db_exec(
        "INSERT INTO absence_justifiee (id_eleve, id_matiere, id_seq, justifie, raison)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE justifie = VALUES(justifie), raison = VALUES(raison)",
        [$eid, $id_matiere, $id_seq, $justifie, $raison]
    );
    $n_saved++;
}

flash_set('succes', $n_saved . ' absence(s) mise(s) à jour.');
header('Location: ' . APP_URL . '/secondaire/pages/absences/' . $back);
exit;
