-- 0016_hors_gabarit.sql — œuvres hors gabarit et profondeur
--
-- Revue du 2026-09-24 : une œuvre « hors gabarit » (trop grande ou trop lourde
-- pour un colis) n'est pas expédiée automatiquement. Le tunnel bascule sur une
-- livraison SUR RENDEZ-VOUS : les modalités (enlèvement, transporteur
-- spécialisé…) se fixent ensemble par téléphone ou visio après la commande.
-- La profondeur complète les dimensions pour paramétrer les colis.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN is_oversized TINYINT(1) NOT NULL DEFAULT 0 AFTER weight_grams,
  ADD COLUMN depth_mm INT UNSIGNED NULL AFTER height_mm;

ALTER TABLE orders
  MODIFY shipping_method ENUM('pickup','shipping','appointment') NOT NULL DEFAULT 'shipping';
