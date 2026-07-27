-- 0008_media_copyright.sql — mention de crédit (copyright) par média
--
-- 04-back-office §7 : la médiathèque gagne l'édition du copyright. Une seule
-- mention par image (décision du 2026-07-27) : un crédit — « © Cédric Taldu »,
-- le nom d'un photographe, une source — ne se traduit pas comme un texte
-- alternatif. Il vit donc sur `media`, pas sur `media_translations`.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE media
  ADD COLUMN copyright VARCHAR(190) NULL AFTER original_name;
