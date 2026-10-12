# SIGES — Système intégré de gestion des établissements scolaires

Application multi-établissement (portail association + une base par école),
pour trois types d'école : **primaire** (privé ou public), **secondaire
général** (programme APC, notes par compétence) et **secondaire technique**
(notes par séquence, filières commerciales et industrielles).
PHP 7.4 → 8.4, MySQL.

## Arborescence

```
SIGES/
├─ index.php, login.php, logout.php, dashboard.php   points d'entrée
├─ mot_de_passe_oublie.php, configurer_securite.php,
│  questions_securite_reporter.php, profil.php        parcours de compte
├─ install.php                                       premier lancement
├─ verif_*.php                                       vérification publique des QR imprimés
│                                                    (adresses figées : ne pas déplacer)
├─ config.php, config.local.php                      configuration
│
├─ noyau/            cœur commun aux trois types d'école
│  ├─ connexion.php, connexion_assoc.php, ecole_contexte.php
│  ├─ fonctions.php, paie_fonctions.php, chemins_historiques.php
│  ├─ accueil.php    vitrine publique (rendue par index.php)
│  ├─ partage/       code commun au primaire et au secondaire (vérification
│  │                 des QR, photos et fiches des élèves, bulletins de paie)
│  ├─ layout/        en-tête, pied de page, menus (primaire / secondaire)
│  └─ ajax/          points d'accès AJAX partagés
│
├─ primaire/         tout le primaire
│  ├─ pages/         modules (élèves, notes, bulletins, finances, paie…)
│  ├─ pdf/           documents PDF, polices, FPDF/TCPDF
│  └─ notes_apc.php, notes_apc_arabe.php
│
├─ secondaire/
│  ├─ commun/        partagé général + technique : pages/ (élèves, classes,
│  │                 matières, paiements, RH, paie, discipline, conseil de
│  │                 classe, statistiques…), pdf/, tableau de bord
│  ├─ general/       pages/ notes et bulletins APC (compétences)
│  └─ technique/     pages/ notes et bulletins par séquence (porté d'ABZ_MBE)
│
├─ association/      portail de l'association (écoles, membres, migrations…)
├─ bd/               schémas, migrations, outils d'administration (bd/assoc/)
├─ assets/           CSS, JS, images, fichiers téléversés (uploads/)
├─ deploy/           modèle .htaccess de production, outils de développement,
│                    archives/ (zips de déploiement, locaux, non versionnés)
├─ docs/             guide d'utilisation, présentation, notes de suivi
└─ vendor/           dépendances Composer (non versionné)
```

Une page du secondaire général qui a une version technique (notes,
bulletins) redirige d'elle-même vers `secondaire/technique/…` quand l'école
est technique (`aiguiller_page_technique()`, `noyau/fonctions.php`).

Les anciennes adresses (`pages/…`, `secondaire/pages/…`, `pdf/…`) sont
redirigées par `.htaccess` ; les chemins de pages enregistrés en base
(privilèges, notifications) sont convertis par `chemin_actuel()`.

Déploiement : voir `bd/assoc/DEPLOIEMENT.md`.
