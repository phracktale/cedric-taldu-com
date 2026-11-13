-- La boutique devient une extension A-Press (lot 3).
-- Elle est déclarée ACTIVE ici, dans la même livraison que le déplacement de
-- ses routes : sur un site déjà en ligne (cedrictaldu.com), la boutique ne
-- disparaît pas, même une seconde. Les migrations du cœur (core_extensions)
-- sont jouées avant celles du site par bin/migrate.php.
-- INSERT IGNORE : si l'administrateur a déjà changé l'état, on ne l'écrase pas.
INSERT IGNORE INTO core_extensions (name, active, version, changed_at)
VALUES ('boutique', 1, '1.0.0', UTC_TIMESTAMP());
