-- 0018_espace_client.sql — connexion à l'espace client par lien e-mail
--
-- Revue du 2026-09-24 : l'acheteur consulte ses commandes, ses factures et son
-- abonnement à la newsletter. Pas de mot de passe : il demande un lien, reçu à
-- l'adresse utilisée pour commander, valable 20 minutes et à usage UNIQUE.
--
-- Le jeton n'est jamais stocké en clair : seule son empreinte SHA-256 l'est,
-- comme un mot de passe. Les jetons consommés ou expirés sont purgeables.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

CREATE TABLE customer_login_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_customer_token (token_hash),
  INDEX idx_customer_token_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
