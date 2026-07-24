-- 0006_editorial.sql — blog, pages éditoriales, messages de contact, redirections
--
-- Reprend 01-modele-de-donnees §6. Lot 4 : éditorial et contact.
--
-- La table `redirects` est déclarée ici (périmètre du lot 4, cf. 08-lots) mais
-- son EXPLOITATION — résolution 301 au changement de slug — relève du lot 6
-- (05-i18n-seo §5). On pose le schéma maintenant pour ne pas fragmenter une
-- migration plus tard ; aucun code du lot 4 ne l'écrit encore.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

-- --------------------------------------------------------------- blog ---

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cover_media_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  event_date DATE NULL,                     -- date de l'exposition, si applicable
  event_place VARCHAR(200) NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  -- Publication différée : un article peut être publié mais daté dans le futur.
  -- La lecture publique exige is_published = 1 ET published_at <= maintenant.
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_post_pub (is_published, published_at),
  CONSTRAINT fk_post_media FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_post_user FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_translations (
  post_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(220) NOT NULL,
  excerpt VARCHAR(400) NULL,
  body LONGTEXT NULL,                       -- HTML assaini À L'ÉCRITURE (06-securite §2)
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  PRIMARY KEY (post_id, locale),
  UNIQUE KEY uq_post_slug (locale, slug),
  CONSTRAINT fk_pot_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- pages éditoriales ---

CREATE TABLE pages (                        -- À propos, Livret, Mentions, Confidentialité, CGV
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,         -- about | booklet | legal | privacy | terms
  cover_media_id INT UNSIGNED NULL,
  attachment_path VARCHAR(255) NULL,        -- PDF du livret, servi par un contrôleur (jamais un chemin client)
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_page_media FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_translations (
  page_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(220) NOT NULL,
  body LONGTEXT NULL,                       -- HTML assaini à l'écriture
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  PRIMARY KEY (page_id, locale),
  UNIQUE KEY uq_page_slug (locale, slug),
  CONSTRAINT fk_pgt_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------ messages de contact ---

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NULL,             -- rempli si le message est rattaché à une œuvre
  sender_name VARCHAR(160) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  -- Le SUJET est fixé côté serveur (06-securite §6.6) ; le texte libre de
  -- l'utilisateur va dans `body`, jamais dans un en-tête d'e-mail.
  subject VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  status ENUM('new','read','answered','spam') NOT NULL DEFAULT 'new',
  ip_hash CHAR(64) NULL,                    -- SHA-256(ip + poivre), purgé à 12 mois
  user_agent VARCHAR(255) NULL,
  spam_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_msg_status (status, created_at),
  CONSTRAINT fk_msg_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- redirections ---
-- Posée pour le lot 6 (05-i18n-seo §5) : le changement d'un slug publié y créera
-- une 301 depuis l'ancien chemin. Aucune écriture au lot 4.

CREATE TABLE redirects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locale CHAR(2) NOT NULL,
  from_path VARCHAR(255) NOT NULL,          -- chemin sans préfixe d'application ni langue
  to_path VARCHAR(255) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_redirect_from (locale, from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
