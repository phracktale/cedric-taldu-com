-- 0014_post_blocks.sql — composition des actualités par blocs éditoriaux
--
-- Revue du 2026-09-24 : les blocs (editor-core, voir 0013) s'ouvrent aux
-- actualités. Comme pour les pages, ils COMPLÈTENT le corps HTML (rendus après
-- lui) au lieu de le remplacer.
--
--   * NULL  → pas de bloc, l'article se limite à son corps ;
--   * [...] → liste ordonnée de blocs, rendue par partials/blocks.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE post_translations
  ADD COLUMN blocks JSON NULL AFTER body;
