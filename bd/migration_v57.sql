-- =====================================================================
--  SIGES — Migration v57 : anti-recul d'horloge système (licence)
-- =====================================================================
--  bd/lib/licence.php::licence_etat_calculer() compare date_expiration à
--  l'horloge système du serveur ('today') — la signature HMAC protège la
--  ligne `licence` contre une modification EN BASE, mais rien ne protégeait
--  jusqu'ici contre une horloge système reculée : reculer la date système
--  avant date_expiration faisait réapparaître une licence expirée comme
--  valide, sans laisser aucune trace (licence_etat() ne fait que lire).
--  Demande explicite du 13/09/2026 (suite à un test de contournement).
--
--  `dernier_maintenant_vu` : le plus récent horodatage système observé lors
--  d'une vérification de licence — ne progresse QUE vers l'avant (jamais
--  remis en arrière). Si l'horloge système courante se retrouve nettement
--  ANTÉRIEURE à ce watermark (tolérance de quelques heures, pour absorber
--  une resynchronisation NTP légitime ou un changement de fuseau), c'est
--  une anomalie : licence_etat() force alors 'expiree' quelle que soit la
--  date_expiration stockée (fail-closed, comme le reste du module).

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'licence_securite' AND column_name = 'dernier_maintenant_vu');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `licence_securite` ADD COLUMN `dernier_maintenant_vu` datetime DEFAULT NULL AFTER `bloque_le`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
