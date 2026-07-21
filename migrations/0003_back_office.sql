-- 0003_back_office.sql — ce que le back-office ajoute au schema
--
-- Les tables `users`, `audit_log` et `rate_limits` existent depuis 0001 : le lot 2
-- en est le premier consommateur, il ne les recree pas. Trois ajouts seulement,
-- chacun exige par une regle des specs.
--
-- RAPPEL : une migration fusionnee n'est JAMAIS modifiee. On en ajoute une.

-- 04-back-office §1 : « 2FA TOTP optionnelle [...] avec codes de secours a usage
-- unique. » Le code n'est jamais stocke en clair — il vaut un mot de passe : sa
-- perte donnerait acces au compte. On conserve SHA-256(code + poivre), comme les
-- empreintes d'IP, et la ligne porte sa propre marque d'usage.
--
-- Pas de suppression a l'usage : une ligne consommee doit RESTER, sinon le meme
-- code pourrait etre re-genere et redevenir valable, et l'artiste ne verrait plus
-- combien de codes il lui reste.
CREATE TABLE user_backup_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,                  -- SHA-256(code + poivre), jamais le code
  used_at DATETIME NULL,                        -- NULL tant que le code n'a pas servi
  created_at DATETIME NOT NULL,
  -- Un doublon rendrait le code utilisable deux fois : la marque « utilise » ne
  -- porterait que sur l'une des deux lignes.
  UNIQUE KEY uq_backup_code (user_id, code_hash),
  INDEX idx_backup_user (user_id, used_at),
  CONSTRAINT fk_backup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 02-front-public §5 et 04-back-office §3 : bande « methode » en bas de la page
-- rubrique, texte libre traduisible et facultatif. HTML assaini A L'ECRITURE,
-- comme `description` : la lecture ne fait plus qu'afficher (06-securite §2).
ALTER TABLE category_translations
  ADD COLUMN method_text TEXT NULL AFTER description;

-- 06-securite §5.5 : « Le nom d'origine n'est conserve que comme metadonnee
-- affichee, echappee. » Il ne sert JAMAIS a nommer le fichier sur le disque —
-- celui-ci recoit bin2hex(random_bytes(16)) et une extension deduite du type
-- reel. Sans cette colonne, l'artiste ne retrouve pas son image dans la
-- mediatheque : toutes portent un nom aleatoire.
ALTER TABLE media
  ADD COLUMN original_name VARCHAR(255) NULL AFTER checksum;
