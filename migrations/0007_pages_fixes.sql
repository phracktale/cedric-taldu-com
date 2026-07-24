-- 0007_pages_fixes.sql — les cinq pages éditoriales à code fixe
--
-- Reprend 02-front §6 et 04-back-office §9. Lot 4 : éditorial et contact.
--
-- Ces pages EXISTENT dans tout environnement, y compris la production sans jeu
-- de démonstration : mentions légales, confidentialité et CGV sont des
-- obligations réglementaires qui ne peuvent pas dépendre d'un seed. Le contenu
-- posé ici est un GABARIT que l'artiste remplace en back-office ; le code, le
-- slug et l'existence, eux, sont structurels.
--
-- Slugs par langue conformes à 05-i18n-seo §2. Le back-office ne peut ni
-- supprimer ni créer une page à code fixe : il ne fait que l'éditer.
--
-- RAPPEL : une migration fusionnée n'est JAMAIS modifiée. On en ajoute une.

INSERT INTO pages (code, is_published, created_at, updated_at) VALUES
  ('about',   1, NOW(), NOW()),
  ('booklet', 1, NOW(), NOW()),
  ('legal',   1, NOW(), NOW()),
  ('privacy', 1, NOW(), NOW()),
  ('terms',   1, NOW(), NOW());

INSERT INTO page_translations (page_id, locale, slug, title, body) VALUES
  ((SELECT id FROM pages WHERE code = 'about'),   'fr', 'a-propos',
      'À propos', '<p>Présentation de l’artiste. À compléter en back-office.</p>'),
  ((SELECT id FROM pages WHERE code = 'about'),   'en', 'about',
      'About', '<p>About the artist. To be completed in the back office.</p>'),
  ((SELECT id FROM pages WHERE code = 'booklet'), 'fr', 'livret',
      'Livret', '<p>Le livret de l’atelier. À compléter en back-office.</p>'),
  ((SELECT id FROM pages WHERE code = 'booklet'), 'en', 'booklet',
      'Booklet', '<p>The studio booklet. To be completed in the back office.</p>'),
  ((SELECT id FROM pages WHERE code = 'legal'),   'fr', 'mentions-legales',
      'Mentions légales', '<p>Mentions légales. À compléter en back-office.</p>'),
  ((SELECT id FROM pages WHERE code = 'legal'),   'en', 'legal-notice',
      'Legal notice', '<p>Legal notice. To be completed in the back office.</p>'),
  ((SELECT id FROM pages WHERE code = 'privacy'), 'fr', 'confidentialite',
      'Confidentialité', '<p>Politique de confidentialité. À compléter en back-office.</p>'),
  ((SELECT id FROM pages WHERE code = 'privacy'), 'en', 'privacy',
      'Privacy', '<p>Privacy policy. To be completed in the back office.</p>'),
  ((SELECT id FROM pages WHERE code = 'terms'),   'fr', 'conditions-generales-de-vente',
      'Conditions générales de vente', '<p>Conditions générales de vente. À compléter en back-office.</p>'),
  ((SELECT id FROM pages WHERE code = 'terms'),   'en', 'terms',
      'Terms and conditions', '<p>Terms and conditions of sale. To be completed in the back office.</p>');
