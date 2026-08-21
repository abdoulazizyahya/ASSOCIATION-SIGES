<?php
// ajax/eleves_par_classe.php — retourne les élèves inscrits (année active)
// d'une classe en JSON. Utilisé pour peupler dynamiquement le select
// « Élève » quand « Classe » change, sans recharger toute la page
// (pages/finances/versement.php).
//
// ⚠️ Réécrit le 10/08/2026 : la version précédente était un reste non
// adapté d'ABZ_MBE (eleve.id/nom/prenom/matricule, table `inscription`,
// annee_scolaire.id — rien de tout ça n'existe dans jaynitaare_v2_bd) —
// code mort qui échouait silencieusement. Même lacune que
// ajax/departements_par_region.php avant sa réécriture le même jour.
//
// ob_start() dès le début : capture tout warning/notice PHP parasite pour
// qu'il ne casse jamais le parsing JSON côté navigateur.
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();

$id_classe = (int) ($_GET['classe'] ?? 0);
$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$eleves = ($id_classe && $val_annee)
    ? db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
         WHERE e.statut = 'actif' ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $val_annee]
      )
    : [];

$out = array_map(fn($e) => [
    'id'    => (int) $e['id_eleve'],
    'label' => trim($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')) . ' (' . $e['Mat_elv'] . ')',
], $eleves);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
