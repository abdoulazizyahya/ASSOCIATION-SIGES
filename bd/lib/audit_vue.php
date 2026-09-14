<?php
// bd/lib/audit_vue.php — libellés et rendu HTML des lignes du journal
// d'audit. Partagé par association/journal.php (réseau) et
// pages/utilisateurs/journal.php (vue directeur / fondateur).
// Suppose h() disponible (fonctions.php).

function audit_vue_evenement(string $e): string
{
    return [
        'connexion'       => 'Connexion',
        'connexion_echec' => 'Échec de connexion',
        'deconnexion'     => 'Déconnexion',
        'action'          => 'Action',
    ][$e] ?? $e;
}

function audit_vue_role(string $r): string
{
    if ($r === '') return '—';
    return [
        'DIRECTEUR'  => 'Directeur',
        'FONDATEUR'  => 'Fondateur',
        'ENSEIGNANT' => 'Enseignant',
        'SECRETAIRE' => 'Secrétaire',
        'COMPTABLE'  => 'Agent financier',
        'SUPERADMIN' => 'Superadmin association',
        'MEMBRE'     => 'Membre association',
    ][$r] ?? ucfirst(strtolower($r));
}

function audit_vue_action(string $a): string
{
    static $L = [
        'visite_ecole'           => 'Entrée dans une école',
        'sortie_ecole'           => 'Sortie d\'une école',
        'etablissement_creation' => 'Création d\'établissement',
        'etablissement_modifie'  => 'Modification d\'établissement',
        'etablissement_supprime' => 'Suppression d\'établissement',
        'ecole_bd_export'        => 'Export de base',
        'ecole_bd_import'        => 'Import de base',
        'ecole_bd_import_echec'  => 'Import de base (échec)',
        'ecole_bd_vidage'        => 'Vidage de base',
        'ecole_bd_creation'      => 'Création de la base',
        'ecole_bd_restauration'  => 'Restauration de base',
        'niu_frappe'             => 'Attribution NIU',
        'niu_creation'           => 'Création NIU',
        'niu_generation_masse'   => 'Génération NIU en masse',
        'niu_config'             => 'Configuration NIU',
        'niu_transfert'          => 'Transfert NIU',
        'niu_reintegration'      => 'Réintégration NIU',
        'niu_sortie'             => 'Sortie NIU du réseau',
        'niu_fusion'             => 'Fusion de NIU',
        'personnel_affectation'  => 'Affectation de personnel',
        'membre_creation'        => 'Création de membre',
        'membre_modifie'         => 'Modification de membre',
        'membre_mdp'             => 'Réinit. mot de passe (membre)',
        'membre_acces'           => 'Changement d\'accès membre',
        'membre_2fa_on'          => 'Activation 2FA',
        'membre_2fa_off'         => 'Désactivation 2FA',
        'migration_ecole'        => 'Migration de schéma',
        'journal_purge'          => 'Purge du journal',
        'mot_de_passe_reinit'    => 'Réinit. mot de passe d\'un collaborateur',
        'mot_de_passe_change'    => 'Changement de mot de passe',
        'role_modifie'           => 'Changement de rôle',
        'utilisateur_cree'       => 'Création d\'un compte',
        'utilisateur_suppr'      => 'Suppression d\'un compte',
        'utilisateur_statut'     => 'Activation / désactivation d\'un compte',
        'questions_secretes'     => 'Configuration des questions secrètes',
        'eleve_suppr'            => 'Suppression d\'un élève',
        'notes_validees'         => 'Validation de notes',
        'bulletins_generes'      => 'Génération de bulletins',
        'paiement_saisi'         => 'Saisie d\'un paiement',
    ];
    return $L[$a] ?? $a;
}

/**
 * Badge « Évènement » (+ action si evenement='action', + cible en dessous).
 * Connexion / déconnexion RÉUSSIES, et simple visite d'école (visite_ecole /
 * visite_ecole_ecriture, association/entrer_ecole.php) : silencieuses ici
 * (demande explicite du 15/09/2026 — pure navigation/consultation, aucune
 * information utile en plus de la date/l'acteur/l'appareil déjà dans les
 * autres colonnes). Échec de connexion reste affiché AVEC sa raison sur la
 * même ligne (« Échec de connexion – mot de passe incorrect ») : signal de
 * sécurité, pas du bruit — voir login.php / association/login.php qui
 * distinguent désormais identifiant/mot de passe incorrect dans `cible`
 * (le message affiché À L'UTILISATEUR, lui, reste volontairement vague :
 * anti-énumération de comptes, le journal n'est visible que des rôles
 * autorisés).
 */
function audit_vue_evenement_badge(array $l): string
{
    $e = $l['evenement'] ?? '';
    if ($e === 'action') {
        if (in_array($l['action'] ?? '', ['visite_ecole', 'visite_ecole_ecriture'], true)) {
            return '<span class="text-muted2">—</span>';
        }
        $out = '<span class="badge badge-soft">' . h(audit_vue_action((string) ($l['action'] ?? ''))) . '</span>';
    } elseif ($e === 'connexion' || $e === 'deconnexion') {
        return '<span class="text-muted2">—</span>';
    } elseif ($e === 'connexion_echec') {
        $out = '<span class="text-danger">' . h(audit_vue_evenement($e))
             . (!empty($l['cible']) ? ' – ' . h($l['cible']) : '') . '</span>';
        return $out;
    } else {
        $out = '<span>' . h(audit_vue_evenement($e)) . '</span>';
    }
    if (!empty($l['cible'])) {
        $out .= '<span class="text-muted2 d-block" style="font-size:.72rem">' . h($l['cible']) . '</span>';
    }
    return $out;
}

/**
 * Icône + libellé principal, par ordre de préférence :
 *   1) nom donné par le compte lui-même (appareil_connu.nom, « Mon compte »)
 *   2) modèle commercial transmis par le navigateur (Android seulement,
 *      ex. « TECNO L34 » — iPhone/iPad/ordinateur ne le transmettent jamais)
 *   3) type générique (Ordinateur / Tablette / Téléphone)
 * Détail secondaire : type (si le libellé principal l'a remplacé) + OS —
 * PAS le navigateur (demande explicite du 15/09/2026, jugé pas utile ici).
 * Adresse MAC délibérément absente : AUCUNE techno web n'y donne accès,
 * même en JavaScript — un navigateur ne la transmet jamais à un site,
 * quel que soit le réseau (contrairement au modèle Android, ce n'est pas
 * une limite qu'on pourrait lever plus tard).
 */
function audit_vue_appareil(array $l): string
{
    $t      = $l['ua_appareil'] ?? '';
    $ico    = ['ordinateur' => 'bi-laptop', 'tablette' => 'bi-tablet', 'mobile' => 'bi-phone', 'bot' => 'bi-robot'][$t] ?? 'bi-question-circle';
    $type   = ['ordinateur' => 'Ordinateur', 'tablette' => 'Tablette', 'mobile' => 'Téléphone', 'bot' => 'Robot / outil'][$t] ?? '—';
    $nom    = trim((string) ($l['appareil_nom'] ?? ''));
    $modele = trim((string) ($l['ua_modele'] ?? ''));

    $lib = $nom !== '' ? $nom : ($modele !== '' ? $modele : $type);
    $reste = array_filter([
        $nom !== '' && $modele !== '' ? $modele : ($nom !== '' ? $type : null),
        $l['ua_os'] ?? null,
    ]);
    $detail = trim(implode(' · ', $reste));

    $out = '<span class="text-nowrap"><i class="bi ' . $ico . ' me-1"></i>' . h($lib) . '</span>';
    if ($detail !== '') {
        $out .= '<span class="text-muted2 d-block" style="font-size:.72rem" title="' . h((string) ($l['ua_brut'] ?? '')) . '">' . h($detail) . '</span>';
    }
    return $out;
}

/** « Ville, Pays » (opérateur réseau en info-bulle). */
function audit_vue_localisation(array $l): string
{
    $ville = $l['geo_ville'] ?? '';
    $pays  = $l['geo_pays'] ?? '';
    $aff   = trim($ville . ($ville && $pays ? ', ' : '') . $pays);
    if ($aff === '') {
        $aff = trim((string) ($l['geo_region'] ?? '')) ?: '';
    }
    if ($aff === '') return '<span class="text-muted2">—</span>';
    $op = $l['geo_operateur'] ?? '';
    return '<span' . ($op ? ' title="' . h($op) . '"' : '') . '>' . h($aff) . '</span>';
}
