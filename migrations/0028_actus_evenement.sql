-- 0028_actus_evenement.sql — l'événement d'une actu, décrit pour Google
--
-- Retours Search Console du 2026-10-26 : le balisage Event doit porter un lieu
-- en Place + PostalAddress (adresse en champs séparés), un statut, un
-- organisateur et une entrée quand ils sont connus.
--
-- L'adresse tenait en un seul champ libre, `event_address` : elle passe dans la
-- rue, à reprendre à la main en champs séparés. Les versions de l'historique
-- qui la portent encore la rendent à la rue à la restauration.
--
-- `event_price_cents` : NULL = non précisé, 0 = entrée libre.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE posts
  ADD COLUMN event_street VARCHAR(200) NULL AFTER event_place,
  ADD COLUMN event_postal_code VARCHAR(16) NULL AFTER event_street,
  ADD COLUMN event_city VARCHAR(120) NULL AFTER event_postal_code,
  ADD COLUMN event_country CHAR(2) NOT NULL DEFAULT 'FR' AFTER event_city,
  ADD COLUMN event_status VARCHAR(20) NOT NULL DEFAULT 'scheduled' AFTER event_url,
  ADD COLUMN event_organizer VARCHAR(200) NULL AFTER event_status,
  ADD COLUMN event_organizer_url VARCHAR(500) NULL AFTER event_organizer,
  ADD COLUMN event_price_cents INT UNSIGNED NULL AFTER event_organizer_url;

UPDATE posts SET event_street = LEFT(event_address, 200) WHERE event_address IS NOT NULL;

ALTER TABLE posts DROP COLUMN event_address;
