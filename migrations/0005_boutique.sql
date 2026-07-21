-- 0005_boutique.sql — reproductions, panier, commandes, TVA, port, Stripe
--
-- Reprend 01-modele-de-donnees §4 et §5. Lot 3 : boutique et paiement.
--
-- TROIS ÉCARTS ASSUMÉS avec 01-modele, chacun motivé sur place :
--   1. `orders.vat_mode` vaut 'taxed' et non 'rate' (voir plus bas) ;
--   2. `order_items` porte `shipping_ht_cents` et `shipping_vat_cents` ;
--   3. `orders.anomaly_note` et `order_items.anomaly` existent, alors que
--      01-modele §5 ne les prévoit pas — 03-boutique §6 et §8.5 les exigent.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

-- --------------------------------------------------------- reproductions ---

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artwork_id INT UNSIGNED NOT NULL,
  kind ENUM('limited','standard') NOT NULL,
  edition_size SMALLINT UNSIGNED NULL,      -- obligatoire si kind='limited'
  -- Incrémenté SOUS VERROU DE LIGNE au paiement, jamais ailleurs
  -- (03-boutique §6). C'est ce compteur qui empêche de vendre le 31e
  -- exemplaire d'une édition de 30.
  editions_sold SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_signed TINYINT(1) NOT NULL DEFAULT 0,
  has_rehaut TINYINT(1) NOT NULL DEFAULT 0,
  -- Décision du 2026-07-21 : un tirage giclée, même signé, numéroté et rehaussé
  -- à la main, reste une reproduction photomécanique au sens de l'art. 98 A
  -- ann. III du CGI — c'est la PLANCHE qui doit être exécutée à la main, pas
  -- l'exemplaire. 'original_print' n'est légitime que pour une vraie estampe.
  vat_category ENUM('original_artwork','original_print','standard_goods')
      NOT NULL DEFAULT 'standard_goods',
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_prod_artwork (artwork_id, is_published, position),
  CONSTRAINT fk_prod_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT ck_edition CHECK (kind <> 'limited' OR edition_size IS NOT NULL),
  -- L'invariant 01-modele §7.4 posé dans le schéma, et non seulement dans le
  -- code : une écriture qui contournerait le dépôt échouerait ici aussi.
  CONSTRAINT ck_editions_sold CHECK (edition_size IS NULL OR editions_sold <= edition_size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_translations (
  product_id INT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  title VARCHAR(200) NOT NULL,              -- « Tirage d'art limité, signé et numéroté »
  description TEXT NULL,
  PRIMARY KEY (product_id, locale),
  CONSTRAINT fk_pt_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  sku VARCHAR(60) NOT NULL UNIQUE,
  size_label VARCHAR(60) NOT NULL,          -- « 30 × 40 cm »
  width_mm SMALLINT UNSIGNED NULL,
  height_mm SMALLINT UNSIGNED NULL,
  is_framed TINYINT(1) NOT NULL DEFAULT 0,
  price_cents INT UNSIGNED NOT NULL,        -- PRIX TTC payé par le client (03-boutique §5.4)
  -- Décrémenté par `UPDATE ... WHERE stock_qty >= :q` avec vérification du
  -- nombre de lignes affectées (01-modele §7.5). UNSIGNED interdit en outre
  -- physiquement le stock négatif : la contrainte applicative et la contrainte
  -- de type se couvrent l'une l'autre.
  stock_qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  weight_grams SMALLINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_variant (product_id, size_label, is_framed),
  INDEX idx_var_product (product_id, is_active, position),
  CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- panier ---

CREATE TABLE carts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token CHAR(64) NOT NULL UNIQUE,           -- 32 octets aléatoires, en hexadécimal
  locale CHAR(2) NOT NULL DEFAULT 'fr',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_carts_updated (updated_at)      -- purge des paniers abandonnés à 60 jours
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUCUN PRIX ICI (01-modele §5). Le montant est recalculé depuis le catalogue
-- à chaque affichage ; le figement n'a lieu qu'à la création de la commande.
CREATE TABLE cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id INT UNSIGNED NOT NULL,
  kind ENUM('original','reproduction') NOT NULL,
  artwork_id INT UNSIGNED NULL,             -- rempli si kind='original'
  variant_id INT UNSIGNED NULL,             -- rempli si kind='reproduction'
  qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  -- Empêche qu'une même œuvre originale figure deux fois (03-boutique §2).
  --
  -- ÉCART ASSUMÉ avec 01-modele §5, qui pose la clé sur
  -- (cart_id, kind, artwork_id, variant_id). Cette clé-là NE PROTÈGE RIEN :
  -- MySQL ne tient jamais deux NULL pour égaux dans un index unique, et une
  -- ligne « original » a variant_id à NULL. Deux insertions de la même œuvre
  -- passaient donc toutes les deux — vérifié, puis corrigé ici.
  --
  -- La colonne générée ramène les deux cibles sur une seule valeur non nulle ;
  -- la contrainte ck_ci_target ci-dessous garantit qu'exactement une des deux
  -- est renseignée, donc que COALESCE ne rend jamais NULL.
  --
  -- VIRTUAL et non STORED : MySQL refuse ON DELETE CASCADE sur la colonne de
  -- base d'une colonne générée STORED (erreur 1215), et les deux cascades
  -- ci-dessous sont ce qui vide les paniers quand l'artiste supprime une œuvre.
  -- Un index unique sur colonne virtuelle est un index secondaire ordinaire
  -- pour InnoDB : la contrainte est aussi ferme.
  target_id INT UNSIGNED GENERATED ALWAYS AS (COALESCE(artwork_id, variant_id)) VIRTUAL NOT NULL,
  UNIQUE KEY uq_cart_line (cart_id, kind, target_id),
  CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
  CONSTRAINT ck_ci_target CHECK (
    (kind = 'original' AND artwork_id IS NOT NULL AND variant_id IS NULL) OR
    (kind = 'reproduction' AND variant_id IS NOT NULL AND artwork_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- commandes ---

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
  -- TVA totale de la commande, PORT COMPRIS (décision du 2026-07-21).
  vat_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL,
  -- ÉCART ASSUMÉ avec 01-modele §5, qui nomme ce cas 'rate' alors que
  -- 03-boutique §5.1 le nomme 'taxed'. Un seul jeton est retenu : le réglage
  -- `vat.mode` et cette colonne doivent porter la même valeur pour que le
  -- figement soit une copie et non une traduction. Deux noms pour un même état
  -- garantissent qu'un `match` finira par manquer un cas.
  vat_mode ENUM('exempt_293b','taxed') NOT NULL DEFAULT 'exempt_293b',
  stripe_session_id VARCHAR(255) NULL UNIQUE,
  stripe_payment_intent_id VARCHAR(255) NULL,
  -- 32 octets aléatoires, comparés en TEMPS CONSTANT (06-securite §8) : c'est
  -- la seule chose qui protège la consultation d'une commande.
  access_token CHAR(64) NOT NULL,
  customer_note TEXT NULL,
  -- AJOUT du lot 3, absent de 01-modele §5 : 03-boutique §6 et §8.5 exigent de
  -- pouvoir signaler une commande encaissée que le stock ne permet pas
  -- d'honorer. « On ne perd jamais un paiement encaissé » — il faut donc un
  -- endroit où dire que quelque chose a mal tourné.
  anomaly_note TEXT NULL,
  tracking_carrier VARCHAR(60) NULL,
  tracking_number VARCHAR(80) NULL,
  paid_at DATETIME NULL,
  shipped_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_orders_status (status),
  INDEX idx_orders_created (created_at),
  INDEX idx_orders_anomaly (anomaly_note(1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les colonnes marquées INSTANTANÉ sont figées à la commande. Une modification
-- ultérieure du catalogue ne doit JAMAIS altérer une commande passée
-- (01-modele §7.9). D'où les ON DELETE SET NULL : supprimer une œuvre du
-- catalogue ne doit pas effacer la ligne de commande qui l'a vendue.
CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('original','reproduction') NOT NULL,
  artwork_id INT UNSIGNED NULL,
  variant_id INT UNSIGNED NULL,
  label VARCHAR(255) NOT NULL,              -- INSTANTANÉ
  sku VARCHAR(60) NULL,                     -- INSTANTANÉ
  qty SMALLINT UNSIGNED NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,   -- INSTANTANÉ, TTC
  total_cents INT UNSIGNED NOT NULL,        -- INSTANTANÉ, TTC = ht_cents + vat_cents
  vat_category ENUM('original_artwork','original_print','standard_goods') NOT NULL, -- INSTANTANÉ
  vat_rate_bps SMALLINT UNSIGNED NOT NULL,  -- INSTANTANÉ : 550 | 2000 | 0 en franchise
  ht_cents INT UNSIGNED NOT NULL,           -- INSTANTANÉ, sur le BIEN seul
  vat_cents INT UNSIGNED NOT NULL,          -- INSTANTANÉ, sur le BIEN seul
  shipping_share_cents INT UNSIGNED NOT NULL DEFAULT 0,  -- quote-part TTC
  -- AJOUT du lot 3, décision du 2026-07-21. Sans ces deux colonnes, les six
  -- invariants de 01-modele §7.6 ne peuvent pas être vrais en même temps dès
  -- que le port porte de la TVA : ou bien `total_cents = ht + vat` tombe, ou
  -- bien `orders.vat_cents` sous-déclare la TVA de la facture.
  shipping_ht_cents INT UNSIGNED NOT NULL DEFAULT 0,
  shipping_vat_cents INT UNSIGNED NOT NULL DEFAULT 0,
  edition_number SMALLINT UNSIGNED NULL,    -- attribué AU PAIEMENT (décision du 2026-07-21)
  -- AJOUT du lot 3 : 'already_sold' | 'edition_exhausted' | 'stock_missing'.
  anomaly VARCHAR(40) NULL,
  INDEX idx_oi_order (order_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_art FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,
  CONSTRAINT fk_oi_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  CONSTRAINT ck_oi_line_total CHECK (total_cents = ht_cents + vat_cents),
  CONSTRAINT ck_oi_shipping_share CHECK (shipping_share_cents = shipping_ht_cents + shipping_vat_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- Stripe ---

-- Idempotence des webhooks (01-modele §7.8). `event_id` est la CLÉ PRIMAIRE :
-- c'est la base, et non le code, qui garantit qu'un événement n'est inséré
-- qu'une fois, même si deux livraisons de Stripe arrivent en parallèle.
CREATE TABLE stripe_events (
  event_id VARCHAR(80) PRIMARY KEY,
  type VARCHAR(80) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  -- Laissé NUL tant que le traitement n'a pas abouti : une exception annule la
  -- transaction, `processed_at` reste nul, et Stripe réessaie (03-boutique §6).
  processed_at DATETIME NULL,
  received_at DATETIME NOT NULL,
  INDEX idx_events_processed (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ TVA ---

-- Taux historisés, JAMAIS en dur dans le code (03-boutique §5.3). Un changement
-- de taux légal AJOUTE une ligne et clôt la précédente par valid_to ; il ne
-- modifie jamais une ligne existante, sans quoi les factures déjà émises
-- changeraient rétroactivement.
CREATE TABLE vat_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('original_artwork','original_print','standard_goods') NOT NULL,
  rate_bps SMALLINT UNSIGNED NOT NULL,      -- points de base entiers : 550 = 5,5 %
  valid_from DATE NOT NULL,
  valid_to DATE NULL,                       -- NULL = en vigueur ; borne INCLUSIVE
  legal_reference VARCHAR(160) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_vat_period (category, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vat_rates (category, rate_bps, valid_from, valid_to, legal_reference, created_at) VALUES
  ('original_artwork', 1000, '2014-01-01', '2024-12-31', 'CGI art. 278 septies (abrogé)', NOW()),
  ('original_artwork',  550, '2025-01-01', NULL, 'CGI art. 278-0 bis I (LF 2024, art. 83)', NOW()),
  ('original_print',    550, '2025-01-01', NULL, 'CGI art. 278-0 bis I / art. 98 A ann. III', NOW()),
  ('standard_goods',   2000, '2014-01-01', NULL, 'CGI art. 278 — taux normal', NOW());

-- ----------------------------------------------------------------- port ---

CREATE TABLE shipping_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,         -- FR | EU | WORLD
  label_fr VARCHAR(80) NOT NULL,
  label_en VARCHAR(80) NOT NULL,
  countries JSON NOT NULL,                  -- ["FR"] | ["DE","BE",...] | ["*"]
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shipping_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  max_weight_grams INT UNSIGNED NOT NULL,   -- borne haute INCLUSIVE de la tranche
  price_cents INT UNSIGNED NOT NULL,
  free_above_cents INT UNSIGNED NULL,       -- franco de port éventuel
  UNIQUE KEY uq_rate (zone_id, max_weight_grams),
  CONSTRAINT fk_rate_zone FOREIGN KEY (zone_id) REFERENCES shipping_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grille tranchée le 2026-07-21 : forfait unique par zone, une seule tranche à
-- 10 kg. Le modèle reste celui de 01-modele §5 : passer à une grille fine sera
-- une insertion de lignes, pas un développement.
INSERT INTO shipping_zones (code, label_fr, label_en, countries, position) VALUES
  ('FR', 'France', 'France', '["FR"]', 10),
  ('EU', 'Union européenne', 'European Union',
   '["AT","BE","BG","HR","CY","CZ","DK","EE","FI","DE","GR","HU","IE","IT","LV","LT","LU","MT","NL","PL","PT","RO","SK","SI","ES","SE"]', 20),
  ('WORLD', 'Reste du monde', 'Rest of the world', '["*"]', 30);

INSERT INTO shipping_rates (zone_id, max_weight_grams, price_cents, free_above_cents)
SELECT id, 10000, 900, 30000 FROM shipping_zones WHERE code = 'FR';
INSERT INTO shipping_rates (zone_id, max_weight_grams, price_cents, free_above_cents)
SELECT id, 10000, 2000, 80000 FROM shipping_zones WHERE code = 'EU';
INSERT INTO shipping_rates (zone_id, max_weight_grams, price_cents, free_above_cents)
SELECT id, 10000, 3500, NULL FROM shipping_zones WHERE code = 'WORLD';

-- ------------------------------------------------------------- réglages ---

-- Régime de TVA au démarrage : franchise en base, décision DÉFINITIVE du
-- 2026-07-21 pour toutes les commandes de la période (01-modele §7.7). La
-- bascule exige de renseigner LES DEUX clés — une date seule ne déclenche rien.
INSERT INTO settings (`key`, value, updated_at) VALUES
  ('vat', '{"mode":"exempt_293b","taxable_from":null}', NOW()),
  ('shipping', '{"packaging_grams":250}', NOW());
