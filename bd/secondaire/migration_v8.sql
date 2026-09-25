-- =====================================================================
--  SIGES — Migration SECONDAIRE v8 : retrait de programme_niveau
-- =====================================================================
--  Revient sur la migration v7 (demande explicite du 25/09/2026) : le
--  module Matières doit rester identique à LAM_ABZ (mêmes onglets, même
--  fonctionnement), sans écran de configuration séparé. Les matières
--  d'une nouvelle classe sont désormais reprises directement d'une classe
--  sœur du même niveau/section déjà affectée dans `discipline`
--  (fonctions.php::secondaire_copier_matieres_niveau()) — plus besoin de
--  la table programme_niveau. Les données déjà affectées dans
--  `discipline` (dont celles copiées depuis programme_niveau par la v7)
--  ne sont PAS touchées ici, seule la table intermédiaire disparaît.
-- =====================================================================

DROP TABLE IF EXISTS `programme_niveau`;
