#!/bin/bash
#
# Initialisation du serveur MySQL, local et preprod.
#
# Ecrit en shell et non en .sql pour que les mots de passe viennent de
# l'environnement : un fichier .sql ne peut pas les interpoler, et les figer
# dans le depot reviendrait a commiter un secret (CLAUDE.md §6).
#
# 06-securite §1 : le compte applicatif n'a que SELECT, INSERT, UPDATE, DELETE.
# Ni DROP, ni CREATE, ni FILE, ni acces a une autre base. Les migrations
# emploient un compte distinct, utilise seulement au deploiement.

set -euo pipefail

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_TEST_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER DATABASE \`${MYSQL_DATABASE}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Compte applicatif : aucune permission de schema.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_TEST_NAME}\`.* TO '${MYSQL_USER}'@'%';

-- Compte de migration : droits de schema, sur ces deux bases seulement.
CREATE USER IF NOT EXISTS '${DB_MIGRATION_USER}'@'%' IDENTIFIED BY '${DB_MIGRATION_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${DB_MIGRATION_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${DB_MIGRATION_USER}'@'%';

FLUSH PRIVILEGES;
SQL

echo "Bases et comptes initialisés : ${MYSQL_DATABASE}, ${DB_TEST_NAME}"
