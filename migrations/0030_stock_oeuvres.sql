-- 0030_stock_oeuvres.sql — stock d'une œuvre : pièce originale, quantité ou illimité
--
-- Demande du 2026-10-27 : une œuvre — ou un objet vendu comme elle, un livre
-- d'artiste par exemple — se vend en pièce originale (unique, réservée au
-- paiement et vendue une fois, comme jusqu'ici), en quantité (exemplaires
-- décomptés à chaque vente, épuisée à zéro) ou sans limite.
--
-- `stock_qty` n'a de sens qu'en quantité ; NULL sinon. Toutes les œuvres
-- existantes restent des pièces originales.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN stock_mode ENUM('original', 'quantity', 'unlimited') NOT NULL DEFAULT 'original' AFTER status,
  ADD COLUMN stock_qty INT UNSIGNED NULL AFTER stock_mode;
