-- =====================================================================
--  jaynitaare_v2 — Migration v36 : Passage en classe supérieure automatique
-- =====================================================================
--  Demande explicite du 16/08/2026 : au Résultat annuel, un onglet pour
--  inscrire/envoyer automatiquement en classe supérieure tous les élèves
--  Admis (décision du Conseil de Classe annuel OU, à défaut, moyenne
--  annuelle >= 10) ; consultable à tout moment ; et dès la création ou
--  l'activation de l'année scolaire suivante, les Admis doivent y figurer
--  automatiquement et les redoublants (moyenne annuelle < 10) rester dans
--  la même classe avec le statut d'inscription "Redoublant".
--
--  Choix : PAS de nouvelle table de décisions — `decision_conseil_annuel`
--  (migration_v8) joue déjà exactement ce rôle (decision + next_classe par
--  élève par année). La « validation » d'une classe (pages/resultat_annuel/
--  index.php, onglet=validation) matérialise simplement, pour les élèves
--  qui n'ont pas encore de décision explicite du Conseil de Classe, la
--  règle par défaut déjà utilisée à l'écran (resultat_annuel_decision(),
--  pages/resultat_annuel/commun.php) en une vraie ligne decision_conseil_
--  annuel — la « Liste provisoire » déjà existante (qui lit cette table)
--  fonctionne alors sans aucune modification.
--
--  Une seule colonne nécessaire : le parcours de progression standard
--  (« classe supérieure ») de chaque classe, pour déterminer next_classe
--  quand aucune décision explicite du Conseil de Classe ne le précise déjà
--  (cas de la règle moyenne >= 10 pure). Auto-référence sur `classe`,
--  nullable (classe terminale, ou pas encore configurée).
-- =====================================================================

ALTER TABLE `classe`
  ADD COLUMN `classe_suivante` int DEFAULT NULL AFTER `Niveau`;

-- Contrainte séparée (plutôt qu'inline) : si `classe_suivante` référence
-- une IDClasses qui n'existe pas encore au moment de l'ALTER (improbable
-- mais évite un échec de migration bloquant sur une base déjà en usage),
-- ADD CONSTRAINT échoue proprement sans annuler l'ajout de colonne
-- ci-dessus (run_migration_v36.php ignore les codes d'erreur attendus).
ALTER TABLE `classe`
  ADD CONSTRAINT `fk_classe_classe_suivante`
  FOREIGN KEY (`classe_suivante`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL;
