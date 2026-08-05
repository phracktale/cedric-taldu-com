-- 0010_prodigi_fulfillment.sql — mapping Prodigi et suivi de fulfillment
--
-- Intégration de l'impression à la demande Prodigi pour les reproductions.
--   * product_variants : le SKU Prodigi et le mode de mise à l'échelle
--     (sizing) qui disent QUOI imprimer et COMMENT le cadrer. Nullable : une
--     variante non encore mappée ne peut simplement pas être soumise à Prodigi.
--   * orders : l'identifiant et le statut de la commande Prodigi correspondante,
--     et la date de soumission (garde d'idempotence : une commande déjà soumise
--     n'est pas re-soumise sur un rejeu de webhook).
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE product_variants
  ADD COLUMN prodigi_sku VARCHAR(60) NULL AFTER sku,
  ADD COLUMN prodigi_sizing VARCHAR(20) NOT NULL DEFAULT 'fillPrintArea' AFTER prodigi_sku;

ALTER TABLE orders
  ADD COLUMN prodigi_order_id VARCHAR(80) NULL AFTER stripe_payment_intent_id,
  ADD COLUMN prodigi_status VARCHAR(40) NULL AFTER prodigi_order_id,
  ADD COLUMN prodigi_submitted_at DATETIME NULL AFTER prodigi_status;
