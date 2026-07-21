# php:8.2-apache et non Nginx ou FrankenPHP : l'image doit reproduire le
# comportement .htaccess + mod_rewrite du mutualise de production, pour que ce
# qui marche en preprod marche en prod (09-environnements §1).
FROM php:8.2-apache

# gd est requis par le re-encodage systematique des images televersees
# (06-securite §5.4), intl par les formats de date et de monnaie par langue.
#
# libavif est compile mais AVIF n'est PAS produit : mesure au lot 2, un derive
# AVIF de 2400 px coute onze secondes sur une image realiste, soit une vingtaine
# de secondes pour les cinq largeurs — au-dela de ce qu'un mutualise accorde a
# une requete. Media::FORMATS reste ['webp','jpg'] ; AVIF est reporte au lot 6,
# avec la generation differee que sa lenteur impose de toute facon.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libavif-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" gd intl opcache pdo_mysql zip \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite pour les .htaccess, mod_headers pour les en-tetes de securite
# posees au niveau serveur sur les fichiers statiques.
RUN a2enmod rewrite headers

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-cedrictaldu.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# storage/ n'est jamais chmod 777 par un script : les permissions sont posees
# ici, par le conteneur (09-environnements §5).
RUN mkdir -p storage/cache storage/logs storage/sessions storage/uploads public/media \
    && chown -R www-data:www-data storage public/media \
    && chmod -R 770 storage
