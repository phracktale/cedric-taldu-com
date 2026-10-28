-- 0031_tirages_sans_retractation.sql — tirages imprimés à la demande, sans droit de rétractation
--
-- Demande du 2026-10-28 : un réglage PAR ŒUVRE signale que ses tirages transmis par API au
-- prestataire (circuit prodigi_auto), imprimés pour le client, ne bénéficient pas du droit de
-- rétractation (art. L221-28 du Code de la consommation). Les éditions limitées, préparées à
-- l'atelier, ne sont pas concernées.
--
-- L'information est FIGÉE sur la ligne de commande, comme le prix : décocher le réglage plus
-- tard ne change pas ce que le client a accepté.
--
-- Ajout de colonnes avec valeur par défaut : aucune donnée existante n'est modifiée.
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN prints_no_withdrawal TINYINT(1) NOT NULL DEFAULT 0 AFTER shipping_on_quote;

ALTER TABLE order_items
  ADD COLUMN no_withdrawal TINYINT(1) NOT NULL DEFAULT 0 AFTER vat_category;
