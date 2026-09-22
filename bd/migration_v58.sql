-- =====================================================================
--  SIGES — Migration v58 : mode de matricule « aléatoire » (primaire)
-- =====================================================================
--  matricule_config.mode (migration v52) n'avait que 'auto' (séquentiel
--  {SEQ}=MAX+1) et 'manuel' (saisie libre). Ajout de 'aleatoire' : même
--  format à jetons que le mode auto ({AA}{AAAA}{NIV}{SEQ} + longueur_seq),
--  mais {SEQ} est tiré au hasard dans l'espace à `longueur_seq` chiffres
--  au lieu d'être incrémenté — demande explicite du 16/09/2026 (matricules
--  non devinables/non séquentiels, pour les écoles qui le souhaitent).
--  Repli automatique sur l'incrément séquentiel si l'espace aléatoire est
--  presque saturé (voir gen_matricule(), fonctions.php) — jamais bloquant.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

ALTER TABLE `matricule_config`
  MODIFY `mode` enum('auto','manuel','aleatoire') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto';
