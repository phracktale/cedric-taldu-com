-- 0020_blocs_reutilisables.sql — bibliothèque de blocs réutilisables
--
-- Retours du 2026-09-25 : des blocs génériques (bannière, sections en colonnes,
-- texte + image…), composés dans l'éditeur de blocs et placés par glisser-
-- déposer dans l'accueil ou dans un template. Un bloc a un nom (pour le
-- back-office) et un contenu par langue, au format editor-core, assaini à
-- l'écriture comme les blocs des pages. Sans traduction anglaise, le contenu
-- français est rendu.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

CREATE TABLE content_blocks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  blocks_fr JSON NULL,
  blocks_en JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
