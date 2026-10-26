-- 0029_techniques.sql — la technique des œuvres devient un code de l'atelier
--
-- Retours Search Console du 2026-10-26 : la technique, saisie en texte libre,
-- l'était tantôt en anglais, tantôt en français — « Oil on canvas » sur la
-- page française. Les techniques de l'atelier sont désormais une liste,
-- traduite à l'affichage (App\Domain\Catalog\Technique). Les anciens libellés,
-- dans les deux langues, deviennent leur code ; un texte libre reste tel quel.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

UPDATE artworks SET technique = 'ink_on_paper'
 WHERE LOWER(TRIM(technique)) IN ('encre de chine sur papier', 'india ink on paper');

UPDATE artworks SET technique = 'oil_on_canvas'
 WHERE LOWER(TRIM(technique)) IN ('huile sur toile', 'oil on canvas');
