-- 0001_init.sql — socle du site
--
-- Reprend 01-modele-de-donnees §1. Les tables metier arrivent avec le lot qui
-- les exploite : media et catalogue au lot 1, reproductions, panier et
-- commandes au lot 3, editorial au lot 4.
--
-- InnoDB partout, pour les cles etrangeres et les transactions.
-- utf8mb4_unicode_ci partout, pour que « Œuvre » et les apostrophes typographiques
-- tiennent, et pour que les comparaisons soient insensibles a la casse et aux
-- accents.
--
-- RAPPEL : une migration fusionnee n'est JAMAIS modifiee. On en ajoute une.

-- Suivi des migrations. Aussi creee par bin/migrate.php avant tout traitement,
-- puisqu'il faut pouvoir la lire pour savoir quoi appliquer ; elle figure ici
-- pour que le schema soit complet a la lecture.
CREATE TABLE IF NOT EXISTS migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrateurs uniquement : le site n'a aucun compte client (00-perimetre §4).
-- Rien a voler cote acheteur.
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,          -- Argon2id
  display_name VARCHAR(120) NOT NULL,
  role ENUM('admin','editor') NOT NULL DEFAULT 'admin',
  totp_secret VARCHAR(64) NULL,                 -- 2FA, optionnelle
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,                   -- verrouillage progressif
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reglages editables en back-office : coordonnees, frais de port, mentions de
-- TVA, textes recurrents. La bascule de regime de TVA est un reglage date, pas
-- une migration (03-boutique-paiement §5).
CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  value JSON NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit, conserve 3 ans (06-securite §9).
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,                  -- artwork.update, order.ship, auth.login_failed
  entity_type VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  meta JSON NULL,
  ip_hash CHAR(64) NULL,                        -- SHA-256(ip + poivre), jamais l'IP en clair
  created_at DATETIME NOT NULL,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitation de debit a fenetre glissante (06-securite §6.3).
-- La cle est SHA-256(portee + IP + poivre) : l'IP en clair n'est jamais stockee,
-- et les empreintes sont purgees a 12 mois.
CREATE TABLE rate_limits (
  bucket_key CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  INDEX idx_rate_window (window_start)          -- purge par fenetre
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
