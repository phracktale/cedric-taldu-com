-- 0022_exposition.sql — une exposition complète dans les actus
--
-- Demande du 2026-09-30 : en plus de la date de début (event_date) et du lieu
-- (event_place), une date de fin, l'adresse, un lien (http/https, contrôlé à
-- l'écriture) et une description par langue.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE posts
  ADD COLUMN event_end_date DATE NULL AFTER event_date,
  ADD COLUMN event_address VARCHAR(300) NULL AFTER event_place,
  ADD COLUMN event_url VARCHAR(500) NULL AFTER event_address;

ALTER TABLE post_translations
  ADD COLUMN event_description TEXT NULL AFTER body;
