-- 0023_historique.sql — historique des versions
--
-- Demande du 2026-09-30 : une fausse manipulation (un bloc retiré de la
-- structure d'un template, une mise en page d'accueil cassée) doit pouvoir se
-- réparer. Chaque ligne est l'état d'un élément AVANT une modification ou une
-- suppression ; restaurer réécrit cet état (et versionne l'état remplacé).
--
-- Rien n'est purgé automatiquement : Paramètres › Historique purge à la main,
-- derrière une alerte où il faut taper PURGER.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

CREATE TABLE revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type VARCHAR(30) NOT NULL,        -- setting | content_block (puis page, post…)
  subject_key VARCHAR(190) NOT NULL,        -- clé du réglage, ou identifiant de l'élément
  label VARCHAR(200) NOT NULL,              -- nom lisible au moment de la version
  action VARCHAR(20) NOT NULL,              -- update | delete | restore
  snapshot LONGTEXT NOT NULL,               -- JSON de l'état remplacé
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_revision_subject (subject_type, subject_key, id),
  INDEX idx_revision_created (created_at),
  CONSTRAINT fk_revision_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
