-- 0026_mot_de_passe.sql — changer et récupérer son mot de passe d'administration
--
-- Demande du 2026-10-19 : le site n'offrait aucun moyen de changer son mot de
-- passe, ni de le récupérer une fois oublié.
--
-- `password_changed_at` : une session ouverte AVANT cette date est refusée
-- (AdminSession). Un changement ferme ainsi les sessions restées ouvertes
-- ailleurs, peut-être celle de qui a pris le mot de passe.
--
-- `admin_password_resets` : liens « mot de passe oublié ». Seule l'empreinte
-- SHA-256 du jeton est stockée ; le lien sert une fois, trente minutes au plus.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE users
  ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;

CREATE TABLE admin_password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_reset_token (token_hash),
  INDEX idx_admin_reset_user (user_id),
  CONSTRAINT fk_admin_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
