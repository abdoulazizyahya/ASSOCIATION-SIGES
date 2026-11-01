<?php
// bd/lib/audit_vue.php — libellés et rendu HTML des lignes du journal
// d'audit. Partagé par association/journal.php (réseau) et
// primaire/pages/utilisateurs/journal.php (vue directeur / fondateur).
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
 * sécurité, pas du bruit — voir compte/login.php / association/login.php qui
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

/**
 * Clic sur une position GPS (demande du 01/11/2026) : l'application Google Maps
 * en premier ; si elle n'est pas installée, la carte OpenStreetMap (href du lien).
 *  - Android : lien « intent » vers Google Maps, repli automatique sur l'URL OSM ;
 *  - iPhone/iPad : schéma comgooglemaps:// ; si la page est toujours affichée
 *    1,5 s plus tard, l'application n'existe pas → OSM ;
 *  - ordinateur : Google Maps (site web) dans un nouvel onglet (01/11/2026).
 * Script émis une seule fois par page.
 */
function audit_vue_script_carte(): string
{
    static $emis = false;
    if ($emis) return '';
    $emis = true;
    return <<<'JS'
<script>
function ouvrirCarteGps(a) {
  var lat = a.dataset.lat, lon = a.dataset.lon, osm = a.href, ua = navigator.userAgent;
  if (/Android/i.test(ua)) {
    location.href = 'intent://maps.google.com/maps?q=' + lat + ',' + lon
      + '#Intent;scheme=https;package=com.google.android.apps.maps;S.browser_fallback_url='
      + encodeURIComponent(osm) + ';end';
    return false;
  }
  if (/iPhone|iPad|iPod/i.test(ua)) {
    var t = setTimeout(function () { if (!document.hidden) location.href = osm; }, 1500);
    document.addEventListener('visibilitychange', function () { if (document.hidden) clearTimeout(t); }, {once: true});
    location.href = 'comgooglemaps://?q=' + lat + ',' + lon + '&center=' + lat + ',' + lon + '&zoom=15';
    return false;
  }
  // Ordinateur : Google Maps (site web) dans un nouvel onglet.
  window.open('https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lon, '_blank', 'noopener');
  return false;
}
</script>
JS;
}

/** « Ville, Pays » (opérateur réseau en info-bulle). Position GPS en priorité (31/10/2026). */
function audit_vue_localisation(array $l): string
{
    if (!empty($l['gps_lat'])) {
        $v = trim((string) ($l['gps_ville'] ?? ''));
        $r = trim((string) ($l['gps_region'] ?? ''));
        $lieu = trim($v . ($v !== '' && $r !== '' ? ', ' : '') . $r);
        if ($lieu === '') $lieu = number_format((float) $l['gps_lat'], 4, '.', '') . ', ' . number_format((float) $l['gps_lon'], 4, '.', '');
        $prec  = (int) ($l['gps_precision'] ?? 0);
        $la = rawurlencode((string) $l['gps_lat']); $lo = rawurlencode((string) $l['gps_lon']);
        $carte = "https://www.openstreetmap.org/?mlat=$la&mlon=$lo#map=13/$la/$lo";
        $txt_prec = $prec ? ($prec >= 1000 ? round($prec / 1000, 1) . ' km' : $prec . ' m') : '';
        // Précision > 5 km : estimation du navigateur d'après l'IP, pas un GPS.
        $approx = $prec > (defined('AUDIT_GPS_PRECISION_MAX') ? AUDIT_GPS_PRECISION_MAX : 5000);
        $titre = ($approx ? "Estimation du navigateur d'après l'adresse internet (localisation de l'appareil indisponible)" : 'Position GPS du navigateur')
               . ($txt_prec ? ' (± ' . $txt_prec . ')' : '');
        return audit_vue_script_carte()
             . '<a href="' . h($carte) . '" target="_blank" rel="noopener" class="text-decoration-none" title="' . h($titre) . '"'
             . ' data-lat="' . h((string) $l['gps_lat']) . '" data-lon="' . h((string) $l['gps_lon']) . '" onclick="return ouvrirCarteGps(this)">'
             . '<i class="bi bi-geo-alt-fill ' . ($approx ? 'text-warning' : 'text-success') . ' me-1"></i>' . h($lieu)
             . ($approx ? ' <small class="text-muted">(± ' . h($txt_prec) . ', approx.)</small>' : '') . '</a>';
    }
    $ville = $l['geo_ville'] ?? '';
    $pays  = $l['geo_pays'] ?? '';
    $aff   = trim($ville . ($ville && $pays ? ', ' : '') . $pays);
    if ($aff === '') {
        $aff = trim((string) ($l['geo_region'] ?? '')) ?: '';
    }
    if ($aff === '') return '<span class="text-muted2">—</span>';
    // Ville de l'IP = passerelle de l'opérateur, pas la position réelle.
    $op    = $l['geo_operateur'] ?? '';
    $titre = "Localisation approximative d'après l'adresse IP (passerelle de l'opérateur"
           . ($op ? ' : ' . $op : '') . ') — position GPS non autorisée';
    return '<span title="' . h($titre) . '">' . h($aff) . ' <small class="text-muted">(approx.)</small></span>';
}
