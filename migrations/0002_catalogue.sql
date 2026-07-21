-- 0002_catalogue.sql — médias, rubriques, séries et œuvres
--
-- Reprend 01-modele-de-donnees §2 et §3. Lot 1 : le catalogue en lecture.
--
-- Principe de traduction : une table « porteuse » contient les données neutres
-- (prix, ordre, statut, relations), une table `*_translations` les champs
-- textuels par langue. La ligne `fr` est obligatoire, la ligne `en` facultative
-- et déclenche le repli documenté en 05-i18n-seo §3.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

-- ---------------------------------------------------------------- médias ---

CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  storage_path VARCHAR(255) NOT NULL,       -- storage/uploads/ab/cd/<aléatoire>.jpg, HORS webroot
  public_basename VARCHAR(120) NOT NULL,    -- base des dérivés dans public/media/
  mime VARCHAR(60) NOT NULL,                -- image/jpeg | image/png | image/webp
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  -- SHA-256 du fichier RÉ-ENCODÉ : deux téléversements de la même image ne
  -- créent qu'une entrée (01-modele §2).
  checksum CHAR(64) NOT NULL,
  focal_x TINYINT UNSIGNED NULL,            -- point d'intérêt pour le recadrage (0-100)
  focal_y TINYINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_media_checksum (checksum),
  UNIQUE KEY uq_media_basename (public_basename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_translations (
  media_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  -- Obligatoire à la publication d'une œuvre (05-i18n-seo §5) : une image sans
  -- alt est inaccessible et invisible des moteurs.
  alt VARCHAR(255) NOT NULL DEFAULT '',
  caption VARCHAR(255) NULL,
  PRIMARY KEY (media_id, locale),
  CONSTRAINT fk_mt_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- rubriques ---

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cover_media_id INT UNSIGNED NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Rien ne se publie par accident.
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_cat_publication (is_published, position),
  CONSTRAINT fk_cat_media FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_translations (
  category_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  eyebrow VARCHAR(160) NULL,                -- surtitre
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,                    -- HTML assaini À L'ÉCRITURE
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  PRIMARY KEY (category_id, locale),
  -- Deux rubriques au même slug rendraient /fr/galerie/encres ambigu ; le même
  -- slug dans deux langues différentes, en revanche, est légitime.
  UNIQUE KEY uq_cat_slug (locale, slug),
  CONSTRAINT fk_ct_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- séries ---

CREATE TABLE series (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_series_cat (category_id, is_published, position),
  CONSTRAINT fk_series_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE series_translations (
  series_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  PRIMARY KEY (series_id, locale),
  UNIQUE KEY uq_series_slug (locale, slug),
  CONSTRAINT fk_st_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- œuvres ---

CREATE TABLE artworks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  series_id INT UNSIGNED NULL,
  reference VARCHAR(40) NOT NULL UNIQUE,    -- référence interne atelier
  year SMALLINT UNSIGNED NULL,
  technique VARCHAR(160) NULL,              -- « Encre de Chine sur papier »
  width_mm SMALLINT UNSIGNED NULL,          -- dimensions en millimètres ENTIERS
  height_mm SMALLINT UNSIGNED NULL,
  is_signed TINYINT(1) NOT NULL DEFAULT 1,
  -- PRIX TTC payé par le client, en centimes. NULL = non vendable.
  -- UNSIGNED : la base refuse ce que le domaine refuse.
  price_cents INT UNSIGNED NULL,
  vat_category ENUM('original_artwork','original_print','standard_goods')
      NOT NULL DEFAULT 'original_artwork',
  status ENUM('draft','available','reserved','sold','not_for_sale') NOT NULL DEFAULT 'draft',
  reserved_until DATETIME NULL,             -- réservation temporaire pendant le paiement
  weight_grams SMALLINT UNSIGNED NULL,      -- pour le calcul du port
  primary_media_id INT UNSIGNED NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_art_cat (category_id, is_published, position),
  INDEX idx_art_series (series_id, is_published, position),
  INDEX idx_art_status (status),
  -- RESTRICT : supprimer une rubrique par erreur emporterait tout le travail
  -- qu'elle contient.
  CONSTRAINT fk_art_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  -- SET NULL : une série est un regroupement, pas un contenant.
  CONSTRAINT fk_art_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL,
  -- SET NULL : perdre une image ne doit pas faire disparaître l'œuvre.
  CONSTRAINT fk_art_media FOREIGN KEY (primary_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE artwork_translations (
  artwork_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  eyebrow VARCHAR(160) NULL,                -- « Œuvre originale · Pièce unique »
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,                    -- HTML assaini À L'ÉCRITURE
  detail TEXT NULL,
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  PRIMARY KEY (artwork_id, locale),
  UNIQUE KEY uq_art_slug (locale, slug),
  CONSTRAINT fk_at_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE artwork_media (
  artwork_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NOT NULL,
  role ENUM('main','detail','context') NOT NULL DEFAULT 'detail',
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Clé composite : le même média ne peut pas être lié deux fois à la même
  -- œuvre, fût-ce avec un rôle différent.
  PRIMARY KEY (artwork_id, media_id),
  INDEX idx_am_ordre (artwork_id, role, position),
  CONSTRAINT fk_am_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT fk_am_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
