# 01 — Modèle de données

MySQL 8 / MariaDB 10.6+, InnoDB, `utf8mb4_unicode_ci`, clés étrangères actives.
Toutes les tables ont `created_at DATETIME NOT NULL` et, quand elles sont modifiables,
`updated_at DATETIME NOT NULL`. Les dates sont stockées en **UTC**.

Principe de traduction : une table « porteuse » contient les données neutres (prix, ordre,
statut, relations) et une table `*_translations` contient les champs textuels par langue.

## 1. Socle

```sql
CREATE TABLE migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL
);

CREATE TABLE users (                        -- administrateurs uniquement
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,      -- Argon2id
  display_name VARCHAR(120) NOT NULL,
  role ENUM('admin','editor') NOT NULL DEFAULT 'admin',
  totp_secret VARCHAR(64) NULL,             -- 2FA optionnelle
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  value JSON NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,              -- artwork.update, order.ship, auth.login_failed
  entity_type VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  meta JSON NULL,
  ip_hash CHAR(64) NULL,                    -- SHA-256(ip + pepper), jamais l'IP en clair
  created_at DATETIME NOT NULL,
  INDEX (entity_type, entity_id), INDEX (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE rate_limits (
  bucket_key CHAR(64) NOT NULL,             -- SHA-256(scope + identifiant)
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
);
```

## 2. Médias

```sql
CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  storage_path VARCHAR(255) NOT NULL,       -- storage/uploads/ab/cd/<aléatoire>.jpg
  public_basename VARCHAR(120) NOT NULL,    -- base des dérivés dans public/media/
  mime VARCHAR(60) NOT NULL,                -- image/jpeg | image/png | image/webp
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  checksum CHAR(64) NOT NULL,               -- SHA-256 du fichier ré-encodé, déduplication
  focal_x TINYINT UNSIGNED NULL,            -- point d'intérêt pour le recadrage (0-100)
  focal_y TINYINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_media_checksum (checksum)
);

CREATE TABLE media_translations (
  media_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  alt VARCHAR(255) NOT NULL DEFAULT '',
  caption VARCHAR(255) NULL,
  PRIMARY KEY (media_id, locale),
  CONSTRAINT fk_mt_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
```

Les dérivés (`320w`, `640w`, `1024w`, `1600w`, `2400w` en AVIF + WebP + JPEG de repli) sont
générés à l'upload dans `public/media/<basename>-<largeur>.<ext>`. La base ne stocke pas la
liste des dérivés : elle est déterministe.

## 3. Catalogue

```sql
CREATE TABLE categories (                   -- rubriques : Encres, Peintures, ...
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cover_media_id INT UNSIGNED NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_cat_media FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
);

CREATE TABLE category_translations (
  category_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  eyebrow VARCHAR(160) NULL,                -- surtitre
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,                    -- texte d'introduction (HTML assaini)
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(300) NULL,
  PRIMARY KEY (category_id, locale),
  UNIQUE KEY uq_cat_slug (locale, slug),
  CONSTRAINT fk_ct_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE series (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_series_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE series_translations (
  series_id INT UNSIGNED NOT NULL, locale CHAR(2) NOT NULL,
  slug VARCHAR(160) NOT NULL, title VARCHAR(160) NOT NULL, description TEXT NULL,
  PRIMARY KEY (series_id, locale), UNIQUE KEY uq_series_slug (locale, slug),
  CONSTRAINT fk_st_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
);

CREATE TABLE artworks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  series_id INT UNSIGNED NULL,
  reference VARCHAR(40) NOT NULL UNIQUE,    -- référence interne atelier
  year SMALLINT UNSIGNED NULL,
  technique VARCHAR(160) NULL,              -- "Encre de Chine sur papier"
  width_mm SMALLINT UNSIGNED NULL,          -- dimensions en millimètres, entiers
  height_mm SMALLINT UNSIGNED NULL,
  is_signed TINYINT(1) NOT NULL DEFAULT 1,
  price_cents INT UNSIGNED NULL,            -- PRIX TTC payé par le client. NULL si non vendable
  vat_category ENUM('original_artwork','original_print','standard_goods')
      NOT NULL DEFAULT 'original_artwork',
  status ENUM('draft','available','reserved','sold','not_for_sale') NOT NULL DEFAULT 'draft',
  reserved_until DATETIME NULL,             -- réservation temporaire pendant le paiement
  weight_grams SMALLINT UNSIGNED NULL,      -- pour le calcul du port
  primary_media_id INT UNSIGNED NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX (category_id, is_published, position), INDEX (status),
  CONSTRAINT fk_art_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_art_series FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL,
  CONSTRAINT fk_art_media FOREIGN KEY (primary_media_id) REFERENCES media(id) ON DELETE SET NULL
);

CREATE TABLE artwork_translations (
  artwork_id INT UNSIGNED NOT NULL, locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  eyebrow VARCHAR(160) NULL,                -- "Œuvre originale · Pièce unique"
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,                    -- HTML assaini
  detail TEXT NULL,                         -- "Pièce unique, réalisée à la main dans..."
  meta_title VARCHAR(180) NULL, meta_description VARCHAR(300) NULL,
  PRIMARY KEY (artwork_id, locale), UNIQUE KEY uq_art_slug (locale, slug),
  CONSTRAINT fk_at_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
);

CREATE TABLE artwork_media (
  artwork_id INT UNSIGNED NOT NULL, media_id INT UNSIGNED NOT NULL,
  role ENUM('main','detail','context') NOT NULL DEFAULT 'detail',
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (artwork_id, media_id),
  CONSTRAINT fk_am_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT fk_am_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
```

## 4. Reproductions

```sql
CREATE TABLE products (                     -- une offre de reproduction pour une œuvre
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NOT NULL,
  kind ENUM('limited','standard') NOT NULL,
  edition_size SMALLINT UNSIGNED NULL,      -- obligatoire si kind='limited'
  editions_sold SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_signed TINYINT(1) NOT NULL DEFAULT 0,
  has_rehaut TINYINT(1) NOT NULL DEFAULT 0,
  -- Par défaut 20 % : un tirage giclée, même signé, numéroté et rehaussé, reste une
  -- reproduction photomécanique au sens de l'art. 98 A ann. III du CGI.
  -- 'original_print' n'est légitime que pour une vraie estampe (planche exécutée à la main).
  vat_category ENUM('original_artwork','original_print','standard_goods')
      NOT NULL DEFAULT 'standard_goods',
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_prod_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT ck_edition CHECK (kind <> 'limited' OR edition_size IS NOT NULL)
);

CREATE TABLE product_translations (
  product_id INT UNSIGNED NOT NULL, locale CHAR(2) NOT NULL,
  title VARCHAR(200) NOT NULL,              -- "Tirage d'art limité, signé et numéroté"
  description TEXT NULL,
  PRIMARY KEY (product_id, locale),
  CONSTRAINT fk_pt_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  sku VARCHAR(60) NOT NULL UNIQUE,
  size_label VARCHAR(60) NOT NULL,          -- "30 × 40 cm"
  width_mm SMALLINT UNSIGNED NULL, height_mm SMALLINT UNSIGNED NULL,
  is_framed TINYINT(1) NOT NULL DEFAULT 0,
  price_cents INT UNSIGNED NOT NULL,
  stock_qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  weight_grams SMALLINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_variant (product_id, size_label, is_framed),
  CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
```

## 5. Panier et commandes

```sql
CREATE TABLE carts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token CHAR(64) NOT NULL UNIQUE,           -- aléatoire, transmis par cookie HttpOnly
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX (updated_at)                        -- purge des paniers abandonnés
);

CREATE TABLE cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id INT UNSIGNED NOT NULL,
  kind ENUM('original','reproduction') NOT NULL,
  artwork_id INT UNSIGNED NULL,             -- rempli si kind='original'
  variant_id INT UNSIGNED NULL,             -- rempli si kind='reproduction'
  qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_cart_line (cart_id, kind, artwork_id, variant_id),
  CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  CONSTRAINT ck_ci_target CHECK (
    (kind='original' AND artwork_id IS NOT NULL AND variant_id IS NULL) OR
    (kind='reproduction' AND variant_id IS NOT NULL AND artwork_id IS NULL))
);
```

> **Aucun prix n'est stocké dans le panier.** Il est recalculé à chaque affichage depuis
> `artworks.price_cents` / `product_variants.price_cents`. Le figement du prix n'a lieu
> qu'au moment de la création de la commande.

```sql
CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(20) NOT NULL UNIQUE,    -- CT-2026-0001
  status ENUM('pending','paid','failed','cancelled','shipped','refunded') NOT NULL DEFAULT 'pending',
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  customer_email VARCHAR(190) NOT NULL,
  customer_name VARCHAR(160) NOT NULL,
  customer_phone VARCHAR(40) NULL,
  shipping_method ENUM('pickup','shipping') NOT NULL DEFAULT 'shipping',
  shipping_address JSON NULL,               -- {line1,line2,postal_code,city,country}
  billing_address JSON NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
  vat_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL,
  vat_mode ENUM('exempt_293b','rate') NOT NULL DEFAULT 'exempt_293b',
  stripe_session_id VARCHAR(255) NULL UNIQUE,
  stripe_payment_intent_id VARCHAR(255) NULL,
  access_token CHAR(64) NOT NULL,           -- consultation de la commande par lien signé
  customer_note TEXT NULL,
  tracking_carrier VARCHAR(60) NULL, tracking_number VARCHAR(80) NULL,
  paid_at DATETIME NULL, shipped_at DATETIME NULL, cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX (status), INDEX (created_at)
);

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('original','reproduction') NOT NULL,
  artwork_id INT UNSIGNED NULL, variant_id INT UNSIGNED NULL,
  label VARCHAR(255) NOT NULL,              -- INSTANTANÉ : "Articulation — 2026, encre..."
  sku VARCHAR(60) NULL,                     -- INSTANTANÉ
  qty SMALLINT UNSIGNED NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,   -- INSTANTANÉ, TTC
  total_cents INT UNSIGNED NOT NULL,        -- INSTANTANÉ, TTC = ht_cents + vat_cents
  vat_category ENUM('original_artwork','original_print','standard_goods') NOT NULL, -- INSTANTANÉ
  vat_rate_bps SMALLINT UNSIGNED NOT NULL,  -- INSTANTANÉ, points de base : 550 | 2000 | 0 en franchise
  ht_cents INT UNSIGNED NOT NULL,           -- INSTANTANÉ
  vat_cents INT UNSIGNED NOT NULL,          -- INSTANTANÉ
  shipping_share_cents INT UNSIGNED NOT NULL DEFAULT 0,  -- quote-part de port ventilée sur la ligne
  edition_number SMALLINT UNSIGNED NULL,    -- n° attribué pour un tirage limité
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,
  CONSTRAINT fk_oi_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);
```

> Les colonnes marquées **INSTANTANÉ** sont figées à la commande. Une modification
> ultérieure du catalogue ne doit **jamais** altérer une commande passée. Test dédié.

```sql
CREATE TABLE stripe_events (                -- idempotence des webhooks
  event_id VARCHAR(80) PRIMARY KEY,
  type VARCHAR(80) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  processed_at DATETIME NULL,
  received_at DATETIME NOT NULL
);

CREATE TABLE vat_rates (                    -- taux historisés, jamais en dur dans le code
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('original_artwork','original_print','standard_goods') NOT NULL,
  rate_bps SMALLINT UNSIGNED NOT NULL,      -- points de base entiers : 550 = 5,5 %, 2000 = 20 %
  valid_from DATE NOT NULL,
  valid_to DATE NULL,                       -- NULL = en vigueur
  legal_reference VARCHAR(160) NULL,        -- "CGI art. 278-0 bis I"
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_vat_period (category, valid_from)
);

-- Amorce (migration 0001). Un changement de taux légal AJOUTE une ligne et clôt la
-- précédente par valid_to ; il ne modifie jamais une ligne existante.
INSERT INTO vat_rates (category, rate_bps, valid_from, valid_to, legal_reference, created_at) VALUES
  ('original_artwork', 1000, '2014-01-01', '2024-12-31', 'CGI art. 278 septies (abrogé)', NOW()),
  ('original_artwork',  550, '2025-01-01', NULL, 'CGI art. 278-0 bis I (LF 2024, art. 83)', NOW()),
  ('original_print',    550, '2025-01-01', NULL, 'CGI art. 278-0 bis I / art. 98 A ann. III', NOW()),
  ('standard_goods',   2000, '2014-01-01', NULL, 'CGI art. 278 — taux normal', NOW());

CREATE TABLE shipping_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,         -- FR | EU | WORLD
  label_fr VARCHAR(80) NOT NULL, label_en VARCHAR(80) NOT NULL,
  countries JSON NOT NULL,                  -- ["FR"] | ["DE","BE",...] | ["*"]
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0
);

CREATE TABLE shipping_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  max_weight_grams INT UNSIGNED NOT NULL,   -- borne haute de la tranche
  price_cents INT UNSIGNED NOT NULL,
  free_above_cents INT UNSIGNED NULL,       -- franco de port éventuel
  UNIQUE KEY uq_rate (zone_id, max_weight_grams),
  CONSTRAINT fk_rate_zone FOREIGN KEY (zone_id) REFERENCES shipping_zones(id) ON DELETE CASCADE
);
```

## 6. Éditorial

```sql
CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cover_media_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  event_date DATE NULL,                     -- date de l'exposition, si applicable
  event_place VARCHAR(200) NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX (is_published, published_at),
  CONSTRAINT fk_post_media FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_post_user FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE post_translations (
  post_id INT UNSIGNED NOT NULL, locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL, title VARCHAR(220) NOT NULL,
  excerpt VARCHAR(400) NULL, body LONGTEXT NULL,   -- HTML assaini à l'écriture
  meta_title VARCHAR(180) NULL, meta_description VARCHAR(300) NULL,
  PRIMARY KEY (post_id, locale), UNIQUE KEY uq_post_slug (locale, slug),
  CONSTRAINT fk_pot_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE pages (                        -- À propos, Livret, Mentions légales, CGV...
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,         -- about | booklet | legal | privacy | terms
  cover_media_id INT UNSIGNED NULL,
  attachment_path VARCHAR(255) NULL,        -- PDF du livret
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);

CREATE TABLE page_translations (
  page_id INT UNSIGNED NOT NULL, locale CHAR(2) NOT NULL,
  slug VARCHAR(190) NOT NULL, title VARCHAR(220) NOT NULL, body LONGTEXT NULL,
  meta_title VARCHAR(180) NULL, meta_description VARCHAR(300) NULL,
  PRIMARY KEY (page_id, locale), UNIQUE KEY uq_page_slug (locale, slug),
  CONSTRAINT fk_pgt_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NULL,             -- message rattaché à une œuvre
  sender_name VARCHAR(160) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  subject VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  status ENUM('new','read','answered','spam') NOT NULL DEFAULT 'new',
  ip_hash CHAR(64) NULL,                    -- purgé à 12 mois
  user_agent VARCHAR(255) NULL,
  spam_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX (status, created_at),
  CONSTRAINT fk_msg_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE SET NULL
);
```

## 7. Invariants métier (chacun a un test)

1. Une œuvre en `sold` ne peut pas être ajoutée à un panier ni figurer dans une nouvelle
   commande.
2. Une œuvre ne peut passer en `sold` qu'une seule fois : la transition
   `available|reserved → sold` s'effectue sous `SELECT ... FOR UPDATE` et échoue si le
   statut a changé entre-temps.
3. `reserved_until` expiré remet automatiquement l'œuvre en `available` (à la lecture et
   par la tâche cron), sauf si la commande liée est payée.
4. `products.editions_sold` ne peut jamais dépasser `edition_size`.
5. `product_variants.stock_qty` ne peut jamais devenir négatif : contrainte applicative +
   `UPDATE ... SET stock_qty = stock_qty - :q WHERE id = :id AND stock_qty >= :q` avec
   vérification du nombre de lignes affectées.
6. Cohérence monétaire d'une commande, vérifiée avant insertion **et** par un test
   d'intégrité rejouable sur toute la base :
   - `Σ order_items.total_cents = subtotal_cents`
   - `Σ order_items.shipping_share_cents = shipping_cents` (la ventilation ne perd ni ne
     crée de centime)
   - `Σ order_items.vat_cents = orders.vat_cents`
   - `order_items.total_cents = ht_cents + vat_cents` sur chaque ligne
   - `orders.total_cents = subtotal_cents + shipping_cents`, la TVA étant **incluse** dans
     ces montants puisque les prix sont stockés TTC
   - en régime `exempt_293b` : tous les `vat_cents` et `vat_rate_bps` sont à zéro
7. Une commande créée avant `vat.taxable_from` conserve `vat_mode = 'exempt_293b'` pour
   toujours ; aucun traitement ultérieur ne recalcule la TVA d'une commande existante.
8. Un `stripe_events.event_id` déjà présent avec `processed_at` non nul n'est jamais
   retraité.
9. Toute ligne `order_items` conserve son libellé, son prix, sa catégorie et son taux de
   TVA même si la source est supprimée du catalogue ou que le taux légal change.
10. Chaque enregistrement traduisible possède **au minimum** sa ligne `fr` ; la ligne `en`
   est facultative et déclenche un repli documenté dans `05-i18n-seo.md`.

## 8. Migrations

- Un fichier par changement : `migrations/0001_init.sql`, `0002_add_series.sql`, …
- Une migration mergée n'est **jamais** modifiée ; on en ajoute une nouvelle.
- `bin/migrate.php` applique les fichiers manquants dans l'ordre, chacun dans une
  transaction quand le moteur le permet, et enregistre l'application dans `migrations`.
- La base de test est reconstruite **depuis les migrations** à chaque exécution de la CI.
