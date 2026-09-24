-- 0015_media_source.sql — recadrage réversible
--
-- Revue du 2026-09-24 : un recadrage écrasait l'original, et deux recadrages
-- successifs rognaient définitivement l'image. Au premier recadrage, l'original
-- est désormais MIS DE CÔTÉ (fichier hors webroot, sous storage/) ; « Rétablir
-- l'original » le remet en place et régénère les dérivés.
--
--   * NULL → l'image n'a jamais été recadrée (ou a été rétablie) ;
--   * chemin relatif à storage/ → original d'avant le tout premier recadrage.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE media
  ADD COLUMN source_storage_path VARCHAR(255) NULL AFTER storage_path;
