-- État des extensions A-Press (lot 2).
-- Une ligne par extension déjà activée une fois. Désactiver met active à 0 sans
-- supprimer la ligne : version garde la dernière version dont les migrations
-- ont été appliquées.
CREATE TABLE IF NOT EXISTS core_extensions (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    active TINYINT(1) NOT NULL DEFAULT 0,
    version VARCHAR(32) NOT NULL,
    changed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
