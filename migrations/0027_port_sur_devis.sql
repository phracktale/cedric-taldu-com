-- 0027_port_sur_devis.sql — port établi sur devis, choisi expressément
--
-- Constaté en production le 2026-10-20 : une œuvre en vente sans poids ne
-- pouvait pas être payée — le port ne se calculait pas, et le tunnel renvoyait
-- au panier sans un mot. Décision du 2026-10-26 : le port « sur devis » est une
-- case du formulaire de l'œuvre. Décochée, le poids est obligatoire pour une
-- œuvre en vente ; cochée, il n'est pas demandé et l'achat passe par un message.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN shipping_on_quote TINYINT(1) NOT NULL DEFAULT 0 AFTER is_oversized;
