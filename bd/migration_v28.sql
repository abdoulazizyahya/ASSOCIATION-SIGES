-- =====================================================================
--  jaynitaare_v2 — Migration v28 : clé primaire manquante sur `obligation`
-- =====================================================================
--  `id_obligation` est AUTO_INCREMENT mais n'était indexé que par une
--  KEY simple, pas une PRIMARY KEY (aucune valeur NULL/dupliquée
--  constatée — 0 ligne concernée). Rien d'autre ne change : même
--  colonne, même auto-incrément, juste la contrainte correcte.
-- =====================================================================

ALTER TABLE `obligation`
  DROP KEY `id_obligation`,
  ADD PRIMARY KEY (`id_obligation`);
