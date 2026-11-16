-- Contenu A-Press (lot 5.2) : entrées et révisions de tous les types du
-- modèle de contenu d'un site.
--
-- Une entrée pointe vers deux révisions au plus : la version EN LIGNE
-- (published_revision_id) et le BROUILLON en cours (draft_revision_id).
-- Enregistrer met à jour le brouillon ; publier en fait la version en ligne.
-- Les révisions ne sont jamais modifiées une fois publiées : elles forment
-- l'historique, d'où l'on restaure en recopiant vers un nouveau brouillon.
--
-- `slug` recopie l'adresse de la version EN LIGNE : c'est elle que le site
-- sert, et deux entrées publiées d'un même type ne peuvent pas la partager.

CREATE TABLE IF NOT EXISTS core_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(64) NOT NULL,
  slug VARCHAR(190) DEFAULT NULL,
  position INT NOT NULL DEFAULT 0,
  draft_revision_id BIGINT UNSIGNED DEFAULT NULL,
  published_revision_id BIGINT UNSIGNED DEFAULT NULL,
  received TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  published_at DATETIME DEFAULT NULL,
  trashed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entry_slug (type, slug),
  KEY idx_entry_type (type, trashed_at, position, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id INT UNSIGNED NOT NULL,
  data LONGTEXT NOT NULL,
  author_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  published_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_revision_entry (entry_id, id),
  CONSTRAINT fk_revision_entry FOREIGN KEY (entry_id) REFERENCES core_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
