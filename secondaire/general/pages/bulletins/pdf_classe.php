<?php
/**
 * PDF de tous les bulletins d'une classe (secondaire général, APC)
 * GET : classe, trim|seq, annee, ordre (alpha|merite), serie (classe mixte),
 *       mise (page|lisible), signature, dl (0|1)
 *
 * Depuis le 30/10/2026, chaque élève reçoit EXACTEMENT le bulletin individuel
 * (pdf.php : compétences détaillées, N/20 par compétence, M/20, coef, MxC,
 * cote, [min-max], appréciation…), les uns à la suite des autres dans un seul
 * PDF — plus de présentation « une ligne par matière » propre à la classe.
 * L'ancienne version est dans l'historique git.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
aiguiller_page_technique('secondaire/technique/pages/bulletins/pdf_classe.php');   // école technique : notes par séquence

if (!(int) ($_GET['classe'] ?? 0)) die('Classe manquante.');
if (!(int) ($_GET['trim'] ?? 0) && !(int) ($_GET['seq'] ?? 0)) die('Periode manquante.');
unset($_GET['eleve'], $_GET['vh']);   // mode classe de pdf.php (jamais l'accès public par QR)
require __DIR__ . '/pdf.php';
