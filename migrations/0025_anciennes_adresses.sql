-- 0025_anciennes_adresses.sql — redirections de l'ancien site WordPress
--
-- Demande du 2026-10-14, au lendemain de la mise en production : l'ancien site
-- (WordPress, permaliens /%postname%/, boutique WooCommerce, galeries et
-- portfolio de l'extension kk-cpt) était exploré par Google. Ses adresses
-- mènent désormais à leur équivalent, en 301, au lieu d'un 404.
--
-- Liste relevée dans l'ancienne base (contenus publiés, catégories, PDF
-- téléversés) et à la racine de l'ancien hébergement (PDF posés à la main).
-- Rangées sous la langue par défaut, sans barre oblique finale : voir
-- RedirectMiddleware. Les renommages de slug ultérieurs les mettent à jour.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

INSERT INTO redirects (locale, from_path, to_path, hits, created_at) VALUES
  -- Pages
  ('fr', '/accueil', '/fr/', 0, NOW()),
  ('fr', '/a-propos', '/fr/a-propos', 0, NOW()),
  ('fr', '/actualites', '/fr/actus', 0, NOW()),
  ('fr', '/boutique', '/fr/oeuvres', 0, NOW()),
  ('fr', '/commander', '/fr/panier', 0, NOW()),
  ('fr', '/contact', '/fr/contact', 0, NOW()),
  ('fr', '/encres', '/fr/galerie/encres', 0, NOW()),
  ('fr', '/encres-2023-2024', '/fr/galerie/encres', 0, NOW()),
  ('fr', '/huiles', '/fr/galerie/peintures', 0, NOW()),
  ('fr', '/mon-compte', '/fr/compte', 0, NOW()),
  ('fr', '/panier', '/fr/panier', 0, NOW()),
  -- Galeries et portfolio (extension kk-cpt)
  ('fr', '/gallery', '/fr/galerie', 0, NOW()),
  ('fr', '/gallery/dernier-travaux', '/fr/galerie/encres', 0, NOW()),
  ('fr', '/gallery/encres', '/fr/galerie/encres', 0, NOW()),
  ('fr', '/gallery/huiles', '/fr/galerie/peintures', 0, NOW()),
  ('fr', '/portfolio', '/fr/oeuvres', 0, NOW()),
  ('fr', '/portfolio/pilier-iv', '/fr/oeuvre/pilier-4', 0, NOW()),
  -- Articles
  ('fr', '/category/non-classe', '/fr/actus', 0, NOW()),
  ('fr', '/done', '/fr/actus', 0, NOW()),
  ('fr', '/exposition-corps-visible-corps-divisible-2025', '/fr/actus', 0, NOW()),
  ('fr', '/wip', '/fr/actus', 0, NOW()),
  ('fr', '/wip-2', '/fr/actus', 0, NOW()),
  ('fr', '/wip-3', '/fr/actus', 0, NOW()),
  ('fr', '/wip-4', '/fr/actus', 0, NOW()),
  ('fr', '/wip-arcane-majeure', '/fr/oeuvre/auto-portrait-au-pendu', 0, NOW()),
  -- Boutique WooCommerce
  ('fr', '/categorie-produit/encre-de-chine-sur-papier', '/fr/galerie/encres', 0, NOW()),
  ('fr', '/categorie-produit/huile-sur-toile', '/fr/galerie/peintures', 0, NOW()),
  ('fr', '/produit/cocon1', '/fr/oeuvre/cocon', 0, NOW()),
  ('fr', '/produit/fondations', '/fr/oeuvre/fondations', 0, NOW()),
  ('fr', '/produit/ora-serrata2', '/fr/oeuvre/ora-serrata', 0, NOW()),
  ('fr', '/produit/repli1', '/fr/oeuvre/repli', 0, NOW()),
  ('fr', '/produit/venus1', '/fr/oeuvre/venus', 0, NOW()),
  -- PDF : livrets successifs, affiche et invitation de l'exposition « Chemin moderne »
  ('fr', '/wp-content/uploads/2023/12/livret-artiste-cedric-taldu-2024.pdf', '/fr/livret', 0, NOW()),
  ('fr', '/wp-content/uploads/2024/12/livret-artiste-cedric-taldu-2024-web2.pdf', '/fr/livret', 0, NOW()),
  ('fr', '/wp-content/uploads/2025/03/livret-artiste-cedric-taldu-2025-web.pdf', '/fr/livret', 0, NOW()),
  ('fr', '/wp-content/uploads/2026/07/livret-d-artiste-cedric-taldu-2026.pdf', '/fr/livret', 0, NOW()),
  ('fr', '/livret-artiste-cedric-taldu-2023.pdf', '/fr/livret', 0, NOW()),
  ('fr', '/affiche-exposition-chemin-moderne-cedric-taldu2025.pdf', '/fr/actus', 0, NOW()),
  ('fr', '/affiche-exposition-chemin-moderne-cedric-taldu2025-pour-indi.pdf', '/fr/actus', 0, NOW()),
  ('fr', '/invitation-exposition-chemin-moderne-cedric-taldu.pdf', '/fr/actus', 0, NOW());
