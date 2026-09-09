-- =====================================================================
--  SIGES — Migration v53 : privilèges par utilisateur (menus / sous-menus)
-- =====================================================================
--  Demande explicite : le superadmin (association, entré en écriture) et
--  le Directeur peuvent RETIRER à un compte précis l'accès à un menu ou
--  un sous-menu — activer/désactiver, restreindre. Granularité retenue :
--  menus et sous-menus (pas les actions individuelles). Sens : RESTREINDRE
--  uniquement (deny-list posée par-dessus les rôles — un compte voit par
--  défaut ce que son rôle autorise ; on lui enlève des entrées précises).
--
--  `acces_utilisateur` : une ligne = une clé d'accès REFUSÉE au compte.
--    cle = 'grp:<Nom du groupe>'  → tout le groupe de menu
--        | '<url de l'entrée>'    → une entrée précise (ex. pages/eleves/liste.php)
--  Voir layout/menu.php (définition du menu + convention de clé),
--  fonctions.php (menu_acces_autorise / acces_page_bloquee) et
--  pages/utilisateurs/acces.php (interface de gestion).
--
--  Convention v51+ : SQL pur, idempotent, appliqué par
--  bd/assoc/migrer_toutes_ecoles.php à chaque base école.

CREATE TABLE IF NOT EXISTS `acces_utilisateur` (
  `id_user` int(11) NOT NULL,
  `cle`     varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `refuse_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`, `cle`),
  KEY `idx_acces_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
