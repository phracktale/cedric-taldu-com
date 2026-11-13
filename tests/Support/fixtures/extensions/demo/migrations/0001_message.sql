-- Données seules (pas de DDL) : la migration reste dans la transaction du test.
INSERT INTO settings (`key`, value, updated_at)
VALUES ('demo.message', '"Bonjour depuis l''extension"', '2026-11-13 10:00:00')
ON DUPLICATE KEY UPDATE value = VALUES(value);
