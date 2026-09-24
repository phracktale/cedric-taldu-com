-- 0017_newsletter_rgpd.sql — consentements RGPD et abonnés à la newsletter
--
-- Revue du 2026-09-24 :
--   * le formulaire de contact recueille un consentement explicite, daté sur le
--     message (preuve, article 7 du RGPD) ;
--   * la newsletter est un abonnement FACULTATIF et jamais précoché, dont on
--     garde la preuve : date, source (contact, commande, compte), langue et
--     formulation exacte présentée.
--
-- Aucun jeton de désinscription n'est stocké : il est signé (HMAC du poivre)
-- et se recalcule pour chaque abonné, y compris dans l'export.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

ALTER TABLE contact_messages
  ADD COLUMN privacy_consented_at DATETIME NULL AFTER user_agent;

CREATE TABLE newsletter_subscribers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  source ENUM('contact','checkout','account') NOT NULL,
  consent_text VARCHAR(255) NOT NULL,
  consented_at DATETIME NOT NULL,
  unsubscribed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_newsletter_email (email),
  INDEX idx_newsletter_actifs (unsubscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
