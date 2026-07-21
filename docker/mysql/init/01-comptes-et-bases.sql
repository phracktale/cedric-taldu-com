-- Initialisation du serveur MySQL local et de preprod.
--
-- 06-securite §1 : le compte applicatif n'a que SELECT, INSERT, UPDATE, DELETE.
-- Ni DROP, ni CREATE, ni FILE, ni acces a une autre base. Les migrations
-- emploient un compte distinct, utilise seulement au deploiement.

CREATE DATABASE IF NOT EXISTS cedrictaldu_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER DATABASE cedrictaldu
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Compte applicatif : aucune permission de schema.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cedrictaldu'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON cedrictaldu.* TO 'cedrictaldu'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON cedrictaldu_test.* TO 'cedrictaldu'@'%';

-- Compte de migration : peut modifier le schema, des deux bases seulement.
CREATE USER IF NOT EXISTS 'cedrictaldu_migrate'@'%' IDENTIFIED BY 'migration';
GRANT ALL PRIVILEGES ON cedrictaldu.* TO 'cedrictaldu_migrate'@'%';
GRANT ALL PRIVILEGES ON cedrictaldu_test.* TO 'cedrictaldu_migrate'@'%';

FLUSH PRIVILEGES;
