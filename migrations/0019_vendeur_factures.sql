-- 0019_vendeur_factures.sql — identité du vendeur sur les factures
--
-- Revue du 2026-09-24 : les factures PDF portent l'identité du vendeur, réglée
-- dans le back-office (Facturation). Pour ce site, elle est pré-remplie avec
-- les mentions déjà publiques des CGV (migration 0009) ; l'artiste la corrige
-- au besoin. INSERT IGNORE : un réglage déjà saisi n'est jamais écrasé.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (
  'shop.seller',
  '{"name":"Cédric Taldu","address":"25 allée des Lilas\\n80470 Dreuil-lès-Amiens\\nFrance","siret":"495 376 436 00046","email":"contact@cedrictaldu.com","extra":"Maison des artistes n° T174380"}',
  NOW()
);
