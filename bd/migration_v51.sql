-- =====================================================================
--  jaynitaare_v2 — Migration v51 : rôle FONDATEUR + socle des rôles
-- =====================================================================
--  Multi-établissement : le superadmin association affecte un agent DANS
--  une école (fonction DIRECTEUR / FONDATEUR / ENSEIGNANT / SECRETAIRE /
--  COMPTABLE). Le FONDATEUR consulte toute son école en LECTURE SEULE et
--  ne peut qu'enregistrer / remplacer le compte DIRECTEUR (voir
--  pages/fondateur/directeur.php + ecole_contexte.php::est_lecture_seule()).
--
--  `fonction` alimente le <select> de rôle de pages/utilisateurs/liste.php
--  — on garantit ici la présence des 5 rôles sur toutes les bases école
--  (INSERT IGNORE : idempotent, ne touche pas les lignes déjà présentes).
--
--  Convention v51+ : SQL pur, autosuffisant, appliqué par
--  bd/assoc/migrer_toutes_ecoles.php à chaque base école.
-- =====================================================================

INSERT IGNORE INTO `fonction` (`id_fonction`) VALUES
  ('DIRECTEUR'),
  ('FONDATEUR'),
  ('ENSEIGNANT'),
  ('SECRETAIRE'),
  ('COMPTABLE');
