-- 0024_ordre_toutes_les_oeuvres.sql — ordre libre de la page « Toutes les œuvres »
--
-- Demande du 2026-10-12 : l'artiste choisit le classement de la page (libre,
-- alphabétique, plus récentes d'abord). L'ordre libre a son propre rang,
-- distinct de `position` qui ne vaut que DANS une galerie : la page comparait
-- jusque-là ces positions entre galeries, d'où un ordre inexplicable.
--
-- Le rang est initialisé sur l'ordre affiché jusqu'ici (position de l'œuvre,
-- puis de sa galerie, puis identifiant) : rien ne bouge à la mise en ligne.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE artworks
  ADD COLUMN works_position INT UNSIGNED NOT NULL DEFAULT 0 AFTER position,
  ADD INDEX idx_art_works (is_published, works_position);

UPDATE artworks a
  JOIN (
    SELECT a2.id,
           ROW_NUMBER() OVER (ORDER BY a2.position ASC, c.position ASC, a2.id ASC) - 1 AS rang
      FROM artworks a2
      JOIN categories c ON c.id = a2.category_id
  ) r ON r.id = a.id
   SET a.works_position = r.rang;
