-- =====================================================================
--  SIGES — Migration v54 : statut + dernière connexion des comptes école
-- =====================================================================
--  Jusqu'ici, le login d'une école (login.php) ne vérifiait AUCUN statut :
--  n'importe quel compte `user` existant pouvait se connecter, même si
--  l'enseignant lié était « inactif ». Deux colonnes corrigent ça et
--  alimentent la console « Comptes » du portail association
--  (association/personnel/liste.php, onglet Comptes) :
--
--   • user.actif              : 1 = connexion autorisée (défaut), 0 = bloquée.
--     login.php refuse désormais un compte actif=0 (message « compte
--     désactivé »). Réversible depuis la console association.
--   • user.derniere_connexion : datetime de la dernière connexion réussie,
--     mise à jour par login.php. Sert à repérer les comptes dormants.
--
--  Convention v51+ : SQL pur, idempotent, appliqué par
--  bd/assoc/migrer_toutes_ecoles.php à chaque base école.

-- Idempotent : ces colonnes ne sont ajoutées que si elles manquent.
SET @has_actif := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user' AND column_name = 'actif');
SET @sql := IF(@has_actif = 0,
    'ALTER TABLE `user` ADD COLUMN `actif` tinyint(1) NOT NULL DEFAULT 1 AFTER `pwd_user`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_dc := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user' AND column_name = 'derniere_connexion');
SET @sql := IF(@has_dc = 0,
    'ALTER TABLE `user` ADD COLUMN `derniere_connexion` datetime DEFAULT NULL AFTER `actif`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
