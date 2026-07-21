#!/bin/sh
#
# Rétablit les droits d'écriture des répertoires montés depuis l'hôte.
#
# Le Dockerfile fait déjà un `chown -R www-data storage public/media`, mais
# docker-compose.yml monte tout le dépôt (`./:/var/www/html`) : le montage
# MASQUE l'arborescence de l'image, et le conteneur retrouve l'appartenance de
# l'hôte — sur Thor, l'uid 1000, pas www-data (uid 33).
#
# Conséquence observée en préproduction le 2026-07-21 : Apache ne pouvait plus
# écrire storage/sessions. PHP repartait d'une session neuve à chaque requête,
# le jeton CSRF changeait entre l'affichage du formulaire et son envoi, et le
# seul symptôme visible était un « 419 Formulaire expiré » à la connexion. Le
# journal applicatif, lui, ne s'écrivait pas non plus — donc ne disait rien.
#
# Le correctif vit ici plutôt que dans une commande de déploiement : il se
# rejoue à chaque démarrage, survit à un `git pull` comme à une reconstruction,
# et vaut pour tout environnement où le dépôt est monté. Personne n'a à s'en
# souvenir.

set -e

RACINE=/var/www/html

# Le groupe est celui du point de montage, et non une valeur en dur : l'hôte de
# développement et Thor n'ont pas le même identifiant, et le propriétaire du
# dépôt doit conserver l'accès à ce qu'il a monté — sans quoi un `git pull` sur
# l'hôte ne pourrait plus lire storage/.
GROUPE_HOTE=$(stat -c '%g' "$RACINE")

for repertoire in storage/cache storage/logs storage/sessions storage/uploads public/media; do
    mkdir -p "$RACINE/$repertoire"
done

chown -R www-data:"$GROUPE_HOTE" "$RACINE/storage" "$RACINE/public/media"

# 2770 : www-data et le propriétaire du dépôt écrivent, personne d'autre ne lit.
# Le bit setgid fait hériter le groupe aux fichiers créés ensuite, sinon les
# sessions et les originaux téléversés redeviendraient illisibles depuis l'hôte.
chmod -R 2770 "$RACINE/storage"

# public/media est servi par Apache, qui est www-data : le propriétaire suffit.
chmod -R 2770 "$RACINE/public/media"

exec "$@"
