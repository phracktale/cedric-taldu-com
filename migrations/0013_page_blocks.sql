-- 0013_page_blocks.sql — composition d'une page par blocs éditoriaux
--
-- Jusqu'ici une page portait un seul champ HTML (`body`). L'audit demande de la
-- composer par SECTIONS/BLOCS déplaçables. On ajoute un document JSON de blocs
-- par langue, au FORMAT d'échange `editor-core` (BlockData[] : {id, type,
-- version, props, children}) — le même contrat que FatPlant, pour que le contenu
-- reste interopérable.
--
--   * NULL  → la page suit encore son `body` HTML historique (repli) ;
--   * []    → page composée mais vide ;
--   * [...] → liste ordonnée de blocs, rendue par partials/blocks.
--
-- Le rendu et l'édition assainissent les valeurs ; la colonne ne stocke que du
-- JSON, jamais du HTML exécuté tel quel.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE page_translations
  ADD COLUMN blocks JSON NULL AFTER body;
