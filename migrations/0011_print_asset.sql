-- 0011_print_asset.sql — fichier d'impression haute définition par œuvre
--
-- Prodigi imprime à partir d'une image PRÊTE À L'IMPRESSION, distincte des
-- dérivés web (plafonnés à 2400 px, profil colorimétrique retiré). L'artiste
-- téléverse donc, par œuvre, un fichier haute définition (JPEG/PNG/PDF) rangé
-- HORS webroot ; toutes les reproductions de l'œuvre s'en servent (Prodigi
-- recadre selon le format via le « sizing » de chaque variante).
--
--   * print_asset_path : chemin relatif du fichier stocké (storage/print/…),
--     NULL tant qu'aucun fichier n'a été fourni.
--   * print_asset_mime : type réel détecté au téléversement, pour servir le
--     fichier avec le bon Content-Type au robot de Prodigi.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN print_asset_path VARCHAR(255) NULL AFTER primary_media_id,
  ADD COLUMN print_asset_mime VARCHAR(100) NULL AFTER print_asset_path;
