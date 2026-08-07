-- 0012_processing_mode.sql — mode de traitement d'une reproduction
--
-- Le site distingue trois natures de vente : l'ŒUVRE ORIGINALE (l'œuvre
-- elle-même, pièce unique), le TIRAGE FINE ART à la demande et l'ÉDITION
-- LIMITÉE rehaussée à la main. Les deux dernières sont des `products`, mais
-- leur CIRCUIT logistique diffère :
--
--   * prodigi_auto  — tirage Fine Art : imprimé et expédié directement par le
--     prestataire (Prodigi), soumis automatiquement après paiement ;
--   * artist_manual — édition limitée : imprimée chez Pixels Avenue, rehaussée,
--     signée et numérotée à l'atelier, puis expédiée par l'artiste. Le site
--     enregistre la vente, décrémente le stock et la liste « à préparer », mais
--     ne transmet RIEN à un prestataire automatiquement.
--
-- Jusqu'ici le circuit était DÉDUIT de la présence d'un SKU Prodigi. On le rend
-- EXPLICITE : une édition limitée ne doit jamais partir en impression auto, même
-- si un SKU Prodigi a été renseigné par erreur.
--
-- Backfill : les éditions limitées existantes passent en manuel ; tout le reste
-- (tirages courants) reste sur le circuit automatique, qui était leur défaut.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE products
  ADD COLUMN processing_mode ENUM('artist_manual', 'prodigi_auto')
    NOT NULL DEFAULT 'prodigi_auto' AFTER kind;

UPDATE products SET processing_mode = 'artist_manual' WHERE kind = 'limited';
