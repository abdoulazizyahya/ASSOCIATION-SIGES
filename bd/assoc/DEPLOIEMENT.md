# Déploiement multi-établissement

L'application fonctionne en **mono-école** sans rien faire (base `DB_NAME` de `config.php`,
défaut `promeducam_jaynitaare`).
Le **multi-établissement** s'active dès que la base annuaire `DB_NAME_ASSOC`
(défaut `promeducam_assoc`) existe.

## 1. Première installation de l'annuaire

```
php bd/assoc/installer.php
```
Crée la base annuaire (`DB_NAME_ASSOC`), y inscrit l'école n°1 (`EC1` = base `DB_NAME`) et le
**compte superadmin** (`admin` / mot de passe affiché). Ce compte a `membre_acces.plein_acces = 1`
sur *toutes* les écoles (`id_etablissement IS NULL`) — c'est ce qui le rend superadmin
(`ecole_contexte.php::est_superadmin_association()`).

Si l'annuaire existait déjà avant ce correctif, promouvoir le compte :
```sql
UPDATE promeducam_assoc.membre_acces SET plein_acces = 1 WHERE id_etablissement IS NULL;
```

## 2. Créer une école

Interface : `/association/` → **Nouvel établissement** (superadmin uniquement).
CLI équivalente : `php bd/assoc/creer_ecole.php`.

Crée la base `promeducam_<slug du nom>` à partir de `bd/assoc/schema_ref_ecole.sql`, y charge les
**données de référence** `bd/assoc/seed_ref_ecole.sql`, l'inscrit dans l'annuaire, cale sa version
de schéma. **Ensuite** : le superadmin affecte 1 `FONDATEUR` et 1 `DIRECTEUR` via
`/association/personnel/affecter.php`.

### Données de référence (`seed_ref_ecole.sql`)

Chargées automatiquement à la **création**, au **vidage** et à la **création de base** d'une
école (`charger_schema_ecole()` → `charger_seed_ref_ecole()`). Communes à toutes les écoles,
elles n'ont pas à être ressaisies :

- **niveaux** (M, I, II, III + LEVEL 1-3) ;
- **groupes de compétences / compétences** (jeux `Fr` et `An`) et leur **affectation aux niveaux**
  (`groupe_competence_niveau`) ;
- **matières / disciplines arabes** (`matiere_arabe`, `groupe_matiere_arabe`, `matiere_niveau_arabe`,
  `discipline_arabe`), critères de conseil ;
- **géographie** Cameroun (`pays`, `region`, `departement`, `arrondissement`) ;
- grades enseignants, questions secrètes, couleurs PDF, catégories de dépense ;
- **`bareme_reference`** — gabarit du barème APC : points oral/écrit/pratique/savoir-être pour
  **les 7 niveaux** (M, I, II, III, LEVEL 1-3) × **13 compétences** (les 11 générales + Arabe /
  Éducation islamique, ces 2 dernières `actif=0` par défaut, activées si l'école ouvre une
  section arabe).

Le **barème de travail** reste dans `discipline` (par classe et par année). Il est dérivé du
gabarit automatiquement : à la **création d'une classe** (`pages/classes/form.php`) et à
l'ouverture de la **première année scolaire** d'une école neuve (`pages/parametres/index.php`,
quand `reporter_bareme_annee()` n'a rien à reporter) — via `appliquer_bareme_reference()`
(`fonctions.php`), non destructif.

### École neuve « prête à l'emploi » (`provisionner_ecole_neuve()`)

À la création d'un établissement — et au **vidage** / à la **création de base** — la base est
amenée à un état directement utilisable (`charger_schema_ecole()` appelle
`provisionner_ecole_neuve()`) :

1. **8 classes standard** — 1ère Année, 2ème Année (niveau M), SIL, CP (I), CE1, CE2 (II),
   CM1 (III), CLASS 1 (LEVEL 1) — chaînées par `classe_suivante` (passage automatique).
2. **Année scolaire courante, ACTIVE** (rentrée = août), avec 3 trimestres + 6 évaluations UA1-UA6.
3. **Barème de travail** (`discipline`) de cette année dérivé du gabarit `bareme_reference`.

Chaque étape est idempotente (ne fait rien si des classes / cette année existent déjà).

**Réappliquer à une école existante** (créée avant cette version, ou jamais configurée) :
```
php bd/assoc/reseeder_ecole.php <CODE|--tout> [--classes] [--bareme] [--sans-backup]
```
- `--classes` : provisionne classes + année active + barème (comme une école neuve).
- `--bareme` : remplit seulement les lignes `discipline` manquantes de l'année active.
- Sauvegarde de sécurité écrite avant (sauf `--sans-backup`).
- ⚠ le rechargement du seed écrase les niveaux/compétences personnalisés par l'école, le cas échéant.

## 2 bis. Supprimer une école

Interface : Fiche de l'établissement → **Supprimer** (superadmin uniquement ; le bouton
n'apparaît que si l'établissement est **inactif** — le désactiver d'abord via *Modifier*).

`association/etablissement_supprimer.php` affiche l'impact (base, tables, Mo, NIU, affectations)
et demande une double confirmation : saisie du **code** + case « la base sera supprimée »
(+ case NIU si des élèves y sont rattachés). `supprimer_etablissement()` (`connexion_assoc.php`)
alors :

- détache l'origine des NIU d'élèves partis ailleurs (NIU conservés), supprime les NIU rattachés ;
- purge `personnel_affectation` (le personnel central est conservé) ;
- neutralise le lien dans `journal_action` (traçabilité gardée) ;
- supprime la ligne `etablissement` (CASCADE : `membre_acces`, `schema_version_etab`) ;
- **`DROP DATABASE`** de la base école — ou, en mode pool, la vide et la remet `libre`.

Les bases `DB_NAME` (école n°1 / repli) et `DB_NAME_ASSOC` (annuaire) sont **protégées** :
la suppression est refusée si `db_name` est l'une d'elles.

## 2 ter. Piloter l'association (interface superadmin)

La barre de navigation `/association/` expose, pour un superadmin :

| Page | Rôle |
|---|---|
| **Tableau de bord** (`dashboard.php`) | Effectifs consolidés (élèves G/F, classes, enseignants) + état de santé par école : base joignable, version de schéma vs dernière migration, année scolaire active, directeur affecté, fraîcheur de la dernière sauvegarde, taille de la base. Chaque anomalie remonte en badge dans la colonne « État ». |
| **Membres** (`membres/index.php`, `membres/voir.php`) | Créer / désactiver un membre, réinitialiser un mot de passe, et régler ses **accès** : globaux (toutes écoles, lecture ou écriture) ou par école. « Écriture globale » = superadmin. Garde-fous : on ne peut ni se désactiver soi-même ni retirer le dernier accès superadmin. Remplace l'édition SQL directe de `membre_acces`. |

**Hiérarchie des comptes** — 3 niveaux :

| Niveau | `membre` | Peut |
|---|---|---|
| **Propriétaire** | `proprietaire=1` (compte fondateur, 1 seul) | tout, **+ accorder / retirer le niveau superadmin** à un autre membre, **+ transférer la propriété** |
| **Superadmin** | `membre_acces` global écriture | créer des écoles, gérer les membres et leurs accès **par école**, frapper les NIU — mais **pas** promouvoir / rétrograder un superadmin, ni modifier le compte du propriétaire |
| Membre | `membre_acces` par école (lecture / écriture) ou global lecture | visiter / gérer les écoles autorisées |

Le propriétaire initial = le 1er compte (`installer.php`). Sur une base existante, `maj_assoc.php`
le désigne (plus ancien superadmin). Le propriétaire est toujours superadmin (anti-verrouillage)
et son compte ne peut être ni désactivé ni modifié par un tiers.
| **Journal** (`journal.php`) | Journal d'audit filtrable (membre / école / action / période) : connexions, visites d'écoles, créations-modifications-suppressions d'établissements, export/import/vidage de bases, transferts NIU, migrations. Bouton de purge des entrées > 12 mois. |
| **Migrations** (`migrations.php`) | Version de schéma de chaque école vs la dernière `bd/migration_v*.sql`, et bouton **Migrer** (par école ou toutes les écoles en retard). Une **sauvegarde de sécurité** est écrite avant application ; arrêt à la première migration en échec. Équivalent UI de `migrer_toutes_ecoles.php`. |
| **Démarrage** (`etablissement_demarrage.php?id=N`) | Checklist « école opérationnelle » : base créée, schéma à jour, fondateur/directeur affectés, année ouverte, classes, logo — avec les liens d'action pour chaque point manquant. Accessible depuis la fiche et le cockpit. |
| **Registre NIU** (`niu/voir.php?niu=…`) | Fiche d'un NIU : identité, école courante, **historique des mouvements**, et actions superadmin — transfert inter-écoles, sortie du réseau, réintégration, **fusion** de deux NIU en doublon. Les doublons potentiels sont listés sur `niu/index.php`. |
| **Sécurité** (`securite.php`) | Chaque membre y active/désactive sa propre double authentification (TOTP). |

Fonctions correspondantes en fin de `connexion_assoc.php` :
`assoc_membres_liste`, `assoc_membre_detail`, `assoc_membre_creer`, `assoc_membre_maj`,
`assoc_membre_mot_de_passe`, `assoc_acces_definir`, `assoc_journal(_actions/_purger)`,
`assoc_cockpit`, `etablissement_checklist`, `assoc_migrations_etat`, `assoc_migrer_ecole`,
`assoc_niu_detail`, `assoc_niu_transferer`, `assoc_niu_sortie`, `assoc_niu_fusionner`,
`assoc_login_bloque`, `assoc_membre_2fa_activer/_desactiver` (+ `bd/lib/totp.php`).

## 3. Migrations de schéma (toutes les écoles)

À chaque nouvelle `bd/migration_v<N>.sql` :
```
php bd/assoc/migrer_toutes_ecoles.php          # (--dry-run pour prévisualiser)
```
Convention v51+ : chaque migration est du **SQL pur autosuffisant** (DDL + backfill), rejouable.

## 4. Clés de signature des QR (par école)

Sans clé propre, une école retombe sur la clé globale de `config.php` → un bulletin d'une école
pourrait être présenté comme celui d'une autre en **vérification hors ligne**.

```
php bd/assoc/generer_cles_verif_ecoles.php     # génère + stocke la clé privée par école
```
Puis coller le bloc `CLES_PUBLIQUES` affiché dans **`verif_bulletin_hors_ligne.html`**
(map `code_école → {x, y}` ; garder la clé `''` = repli global pour les QR déjà imprimés).

`--force` régénère (invalide les QR offline déjà imprimés par l'école) ; `--only=CODE` cible une école.

## 5. Isolation des fichiers uploadés

Les logos / signatures / pièces de dossier sont rangés par école sous
`assets/uploads/etab/<code>/` (voir `fonctions.php::upload_prefixe_etab()`).
Migration unique des fichiers existants (noms fixes partagés → sous-dossiers) :
```
php bd/assoc/isoler_uploads_ecoles.php         # (--dry-run d'abord)
```

## 6. Sauvegardes et restauration

```
php bd/assoc/sauvegarder_toutes_ecoles.php [dossier_cible] [--gzip]
```
Dump `mysqldump` de l'annuaire + chaque base école dans un dossier horodaté ; garde les 14
dernières. **Planifier une fois par jour** (Planificateur de tâches Windows / cron).
Sauvegarder aussi le dossier `assets/uploads/` (logos, signatures, photos, dossiers élèves —
non inclus dans les dumps SQL).

Variable d'env `MYSQLDUMP` si l'exécutable n'est pas trouvé automatiquement.

**Copie hors-site** (fortement recommandée) : définir dans `config.local.php`
`BACKUP_OFFSITE_DIR` (dossier réseau / disque monté / dossier synchronisé cloud) et/ou
`BACKUP_OFFSITE_CMD` (rclone / rsync / scp / `aws s3`, appelée avec `<dossier> <horodatage>`).
Le script recopie le dossier horodaté après chaque exécution ; un échec de copie fait sortir
en code ≠ 0 (visible dans le Planificateur).

**Restauration d'une école** :
```
php bd/assoc/restaurer_ecole.php <CODE> <fichier.sql[.gz]> [--oui]
```
Écrase la base de l'école `<CODE>` par le contenu du fichier (accepte `.sql` et `.sql.gz`).
Une **sauvegarde de sécurité de l'état courant** est écrite dans `bd/sauvegardes/` avant
tout écrasement. Sans `--oui` : demande de taper `OUI` (interactif). La base doit exister
(sinon la créer d'abord : fiche → « Créer la base »).

*Test de restauration* recommandé une fois par mois : créer une école jetable, y restaurer
la dernière sauvegarde d'une vraie école, vérifier les effectifs, supprimer l'école jetable.

## 7. Production (recommandations)

- **Double authentification (2FA)** des membres — TOTP (Google Authenticator / Authy…).
  Sur une installation existante, ajouter les colonnes/tables :
  ```
  php bd/assoc/maj_assoc.php
  ```
  Puis chaque membre l'active lui-même : `/association/securite.php` (lien « Ma double
  authentification » depuis Membres). Un superadmin peut la **retirer** pour un membre qui a
  perdu son téléphone : fiche membre → « Retirer ». Recommandé au moins pour les superadmins.
- **Limitation des connexions** : 8 échecs sur un même identifiant OU une même IP en 15 min
  → connexion bloquée 15 min (`login_echec`, `assoc_login_bloque()`). Actif automatiquement
  dès que `maj_assoc.php` a tourné.
- **1 seul compte MySQL** partagé pour toutes les bases (`DB_USER/DB_PASS` de `config.php`) —
  choix retenu. Si un jour une isolation par base est voulue, il faudrait porter les
  identifiants par école (non implémenté).
- **Résolution par sous-domaine** (1 sous-domaine par école) : renseigner `ASSOC_DOMAINE`
  (dans `config.local.php` en prod) et le `sous_domaine` de chaque école (fiche → Modifier).
  Pour un **seul** sous-domaine partagé (cas Camoo, cf. §8), laisser `ASSOC_DOMAINE=''`,
  définir `APP_HOTE`, et la résolution se fait par session (choix au login) + `?ec=CODE`.
- **URL publique des QR par école** : champ `verif_base_url` (fiche → Modifier) si chaque école
  a son domaine. Vide en LAN → détection automatique de l'IP réseau.
- **Page d'accueil neutre** : dès qu'il y a > 1 école active, `/login.php` n'affiche aucun logo
  d'école avant sélection. Les pages `verif_*.php` exigent `?ec=CODE` (les QR le portent déjà).

## 8. Hébergement mutualisé en ligne (Camoo / cPanel) — 1 seul sous-domaine

Objectif : servir la même application sur **`promeducam.beero.cm`**, l'utilisateur choisit
son école sur la page d'accueil puis se connecte. Le déploiement **LAN reste en parallèle**
(bases distinctes, aucune synchro). Plan détaillé : `~/.claude/plans/curried-baking-quill.md`.

### 8.1 Config par environnement

`config.php` charge `config.local.php` (racine, **gitignoré**) en premier ; chaque constante
n'est posée que si `config.local.php` ne l'a pas déjà définie. Modèle : **`config.local.php.example`**.
En LAN : ne pas créer `config.local.php` → comportement historique.

Sur le serveur, `config.local.php` définit au minimum : `DB_HOST/DB_USER/DB_PASS`,
`DB_NAME` (= base EC1), `DB_NAME_ASSOC`, `APP_URL=''`, `APP_HOTE='promeducam.beero.cm'`,
`ECOLE_POOL_ACTIF=true`, `BULLETIN_VERIF_BASE_URL='https://promeducam.beero.cm'`,
`BACKUP_TOKEN=<aléatoire>`. **Garder la même** `BULLETIN_VERIF_PRIVATE_KEY_PEM` qu'en LAN.

`APP_HOTE` court-circuite la résolution par sous-domaine (`ecole_contexte.php`) : l'école vient
de la session (login) + `?ec=CODE` pour les pages publiques. Laisser tous les
`etablissement.sous_domaine` à `NULL`.

### 8.2 Bases MySQL (cPanel)

Le compte MySQL mutualisé **ne peut pas** `CREATE DATABASE` depuis PHP. On crée donc les bases
à la main dans le cPanel :

1. 1 utilisateur MySQL (ex. `promeduca_app`), **ajouté à CHAQUE base** avec `ALL PRIVILEGES`.
2. Bases « réelles » : `promeduca_assoc`, `promeduca_ec1`, `promeduca_ec2`, `promeduca_mh`.
3. **Pool** de bases VIDES : `promeduca_pool01` … `promeduca_pool12` (marge pour ~10 écoles).
4. MultiPHP : **PHP ≥ 8.1** sur le sous-domaine ; activer SSL (AutoSSL / Let's Encrypt).

### 8.3 Reprise des écoles existantes

`mysqldump` local de l'annuaire, EC1, EC2, MH → nettoyer (`CREATE DATABASE`/`USE`/`DEFINER`)
→ importer via phpMyAdmin dans `promeduca_assoc` / `_ec1` / `_ec2` / `_mh`. Puis, dans
`promeduca_assoc` :
```sql
UPDATE etablissement SET db_name='promeduca_ec1', sous_domaine=NULL WHERE code='EC1';  -- idem EC2, MH
UPDATE membre_acces  SET plein_acces=1 WHERE id_etablissement IS NULL;                 -- superadmin
```

### 8.4 Pool de bases

Enregistrer les bases vides pré-créées :
```
/bd/assoc/pool_enregistrer.php?db=promeduca_pool01,promeduca_pool02,…
```
Refuse toute base inexistante ou non vide. Ensuite `/association/` → **Nouvel établissement**
consomme automatiquement une base `libre` du pool (aucun `CREATE DATABASE`), y charge le
schéma de référence, l'inscrit à l'annuaire, marque la base `consomme`. Ré-alimenter le pool
au besoin (créer d'autres bases vides au cPanel + `pool_enregistrer.php`).

### 8.5 Sauvegardes (sans shell)

```
/bd/assoc/sauvegarder_php.php?token=<BACKUP_TOKEN>&gzip=1
```
Dump logique **PHP pur** (annuaire + chaque base école) → `bd/sauvegardes/<horo>/`, rétention 14.
**Cron cPanel** quotidien :
```
/usr/bin/php /home/<compte>/promeducam.beero.cm/bd/assoc/sauvegarder_php.php token=<BACKUP_TOKEN> --gzip
```
Compléter par la sauvegarde **fichiers** du cPanel pour `assets/uploads/` (photos, dossiers,
logos, signatures — hors dumps SQL). `sauvegarder_toutes_ecoles.php` (mysqldump) reste pour le LAN.

### 8.6 Déploiement du code

FTP/Git du dépôt dans le docroot du sous-domaine **sans** `.git`, `bd/sauvegardes/`, ni les
uploads locaux. Déposer `config.local.php`. Copier **`deploy/htaccess-prod.txt`** → `.htaccess`
(HTTPS forcé, `/bd/` et fichiers sensibles bloqués — débloquer `/bd/` par IP le temps des
opérations d'admin des §8.3‑8.5). Créer + rendre inscriptibles
`assets/uploads/{eleves,dossiers_eleves,profils,etab}`. Transférer les fichiers uploads des
3 écoles.

### 8.7 Mise en service

`https://promeducam.beero.cm/` → `login.php` avec sélecteur (EC1/EC2/MH). Puis
`/bd/assoc/migrer_toutes_ecoles.php?dry=1` (et sans `dry` si retard de schéma), test de
connexion par école, test « Nouvel établissement », re-blocage de `/bd/`.

## Rôles

| Rôle | Où | Peut |
|---|---|---|
| Superadmin association | `/association/` | créer/modifier/désactiver des écoles, affecter un agent à une école, registre NIU, consultation lecture seule de toutes les écoles |
| Fondateur | 1 école | **lecture seule** de toute son école + créer/remplacer/désactiver le compte DIRECTEUR (`pages/fondateur/directeur.php`) |
| Directeur | 1 école | administration complète de son école, dont l'affectation des enseignants aux classes (2 grilles : française / arabe) |
| Secrétaire | 1 école | scolarité (vue complète) |
| Comptable | 1 école | finances / dépenses |
| Enseignant | 1 école | **uniquement ses classes** de sa piste (française OU arabe — personnes distinctes) : notes, bulletins, élèves, documents |
