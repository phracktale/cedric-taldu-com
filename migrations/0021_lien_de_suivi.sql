-- 0021_lien_de_suivi.sql — lien de suivi du transporteur
--
-- Retours du 2026-09-25 (Boutique › Commandes) : la liste des commandes donne
-- le suivi de chaque envoi. L'imprimeur (Prodigi) fournit, avec le numéro, le
-- lien de suivi du transporteur ; on le conserve (https seulement, contrôlé à
-- la lecture de la réponse) pour le rendre cliquable.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE orders
  ADD COLUMN tracking_url VARCHAR(500) NULL AFTER tracking_number;
