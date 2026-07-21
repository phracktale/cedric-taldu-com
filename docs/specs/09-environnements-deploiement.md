# 09 — Environnements et déploiement

## 1. Les trois environnements

| Env | Machine | URL | Serveur | Base |
|---|---|---|---|---|
| **local** | Poste Windows **ou** Linux (homelab) | `http://localhost:8000/cedric-taldu` | **Serveur web interne de PHP** (`composer serve`) — aucun Docker en local | Base de test de Thor, atteinte en LAN |
| **preprod** | **Thor** `192.168.1.36`, Docker | `https://customer.phracktale.com/cedric-taldu` | Docker `php:8.2-apache`, TLS terminé par Heimdall | MySQL 8 en conteneur |
| **prod** | o2switch / OVH mutualisé `@decision` | `https://cedrictaldu.com` | Apache + `.htaccess`, PHP 8.2 | MySQL mutualisé |

Chaîne réseau du homelab :
```
Internet (82.66.11.72) → Heimdall (192.168.1.195, Nginx + TLS) → Thor (192.168.1.36, Docker)
```

> **Conséquence majeure sur l'architecture :** le site tourne sous un **préfixe de chemin**
> en preprod (`/cedric-taldu`) et à la racine en production. Aucune URL absolue de chemin
> ne peut être écrite en dur, nulle part — ni en PHP, ni en CSS, ni en JS, ni dans les
> e-mails, ni dans les URL de retour Stripe.

L'image Docker est volontairement `php:8.2-apache` et **non** Nginx/FrankenPHP : elle
reproduit le comportement `.htaccess` + `mod_rewrite` de la production mutualisée. Ce qui
marche en preprod marche en prod.

## 2. Ports à réserver `@decision`

À ajouter dans `HOMELAB/_INFRA_DEVOPS/homelab-desk/docs/infra/port-routing-table.md`
(18110 = ENERIA ; 18120 et 18483 sont libres au relevé du 2026-07-11) :

| Usage | Port proposé | Bind | Conteneur |
|---|---|---|---|
| Application HTTP | **18120** | `0.0.0.0:18120` | `cedric_taldu_app` |
| Réserve HTTPS interne | 18483 | — | (non utilisé) |
| MySQL | **13306** | `192.168.1.36:13306` (LAN uniquement) | `cedric_taldu_db` |
| phpMyAdmin | **28083** | `192.168.1.36:28083` (LAN uniquement) | `cedric_taldu_pma` |
| MailHog (preprod) | **8027** | `192.168.1.36:8027` | `cedric_taldu_mailhog` |

Vhost Heimdall `customer.phracktale.com.conf`, à ajouter à côté du bloc `/eneria/`
(même certificat Let's Encrypt `customer.phracktale.com`, exp. 2026-10-09) :

```nginx
location /cedric-taldu/ {
    proxy_pass http://192.168.1.36:18120/;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Prefix /cedric-taldu;
    proxy_read_timeout 60s;
    client_max_body_size 32m;          # uploads d'images d'œuvres en haute définition
}
```

## 3. Gestion du préfixe de chemin — règles de code

1. `APP_BASE_PATH` est une variable d'environnement (`""` en prod, `/cedric-taldu` en
   preprod). Elle est **la seule** source de vérité.
2. `Core\Request` détermine le préfixe dans cet ordre :
   `APP_BASE_PATH` explicite → en-tête `X-Forwarded-Prefix` **si et seulement si** la
   requête vient d'un proxy de confiance → chaîne vide.
3. Toute URL produite passe par `Service\I18n\UrlGenerator` :
   - `route('artwork.show', ['locale' => 'fr', 'slug' => $s])` → `/cedric-taldu/fr/oeuvre/…`
   - `asset('css/site.css')` → `/cedric-taldu/assets/css/site.css?v=<hash>`
   Un test de sécurité (`BasePathTest`) rejoue l'ensemble des routes avec un préfixe non
   vide et vérifie qu'aucune réponse ne contient d'URL interne sans le préfixe.
4. Les CSS n'utilisent que des chemins **relatifs** (`url(../fonts/jost.woff2)`).
5. Le JS ne devine jamais une URL : le préfixe lui est transmis une fois, via
   `<body data-base="…">`, lu par `app.js`.
6. Cookies (`session`, `cart_token`, `csrf`) portent `path = APP_BASE_PATH ?: '/'`, pour
   ne pas fuiter vers les autres applications de `customer.phracktale.com`.
   **C'est un point de sécurité, pas de confort** : deux applications sur le même domaine
   partagent l'espace de cookies.
7. Le nom du cookie de session est préfixé par l'application (`ct_session`), afin d'éviter
   toute collision avec ENERIA ou une autre application du même domaine.
8. `APP_URL` (absolue, avec préfixe) sert aux liens canoniques, au `sitemap.xml`, aux
   e-mails et aux URL de retour Stripe. Jamais reconstruite depuis `Host`.

## 4. Proxy de confiance et en-têtes transférés

- `TRUSTED_PROXIES=192.168.1.195` en preprod, vide en prod.
- `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Prefix`, `X-Forwarded-For` ne sont
  lus **que** si `REMOTE_ADDR` figure dans `TRUSTED_PROXIES`. Sinon ils sont ignorés
  intégralement — un client ne doit jamais pouvoir se déclarer en HTTPS, changer l'hôte
  perçu, ni usurper une IP pour contourner la limitation de débit.
- `HttpsTest` et `SpoofedHeaderTest` couvrent ces deux cas.

## 5. Développement local

Le développement se fait indifféremment sous **Windows** et sous **Linux (homelab)**,
**sans Docker en local** : Docker est réservé à Thor. Le site tourne avec le serveur
web interne de PHP (`composer serve`, routeur `bin/router.php` qui reproduit le peu de
réécriture des `.htaccess`), et les tests d'intégration s'adressent à la base
`cedrictaldu_test` de Thor par le LAN.

Deux comptes MySQL dédiés au développement y sont créés, reflet exact de la séparation
de production (06-securite §1) : `cedrictaldu_dev` a les droits de schéma pour les
migrations, `cedrictaldu_dev_app` n'a que SELECT, INSERT, UPDATE, DELETE. Ni l'un ni
l'autre n'a le moindre droit sur la base de préproduction.
Contraintes qui en découlent, toutes vérifiables :

- Fins de ligne **LF** partout : `.gitattributes` avec `* text=auto eol=lf` et
  `*.sh text eol=lf`.
- **Casse des chemins significative** : les noms de classes, de templates et d'assets
  doivent correspondre exactement. Un test parcourt les inclusions de templates et vérifie
  la correspondance exacte avec le système de fichiers.
- Aucun chemin absolu Windows dans le code ou les scripts.
- Les scripts de `bin/` sont exécutables en `sh` POSIX, pas en PowerShell.
- Les permissions de `storage/` sont posées par le conteneur (`www-data`), pas par un
  `chmod 777` dans un script.

```yaml
# docker-compose.yml (extrait de principe)
services:
  app:
    build: ./docker/php
    ports: ["18120:80"]
    volumes:
      - ./:/var/www/html:cached        # code monté, comme pour ENERIA
    environment:
      APP_ENV: dev
      APP_BASE_PATH: /cedric-taldu
    depends_on: [db, mailhog]
  db:
    image: mysql:8
    ports: ["13306:3306"]
    environment:
      MYSQL_DATABASE: cedric_taldu
    volumes: ["dbdata:/var/lib/mysql"]
  mailhog:
    image: mailhog/mailhog
    ports: ["8027:8025"]
```

`DocumentRoot` du conteneur pointe sur `/var/www/html/public`. C'est la configuration de
référence ; le `.htaccess` racine de repli n'existe que pour le mutualisé.

## 6. Déploiement

**Preprod (Thor)** — code monté, comme ENERIA :
```bash
ssh phracktale@thor
cd /srv/customers/cedric-taldu && git pull
docker compose exec app php bin/migrate.php
docker compose exec app php bin/cache-clear.php
```

**Production (mutualisé)** :
```bash
git pull                        # vendor/ est commité, aucun composer install requis
php bin/migrate.php
php bin/cache-clear.php
```

Aucun déploiement ne s'exécute si la suite de tests n'est pas verte. Le script de
déploiement refuse de tourner sur un dépôt avec des modifications non commitées.

## 7. Preprod : ne pas polluer le web ni les clients

- `X-Robots-Tag: noindex, nofollow` sur **toutes** les réponses quand `APP_ENV != prod`,
  plus un `robots.txt` interdisant tout. Testé.
- Stripe en **clés de test** exclusivement ; un contrôle au démarrage refuse de démarrer si
  `APP_ENV=prod` avec une clé `sk_test_`, et inversement.
- Tous les e-mails sortants sont capturés par MailHog : le transport SMTP pointe sur
  MailHog et un garde-fou interdit tout envoi vers un domaine externe hors production.
- Un bandeau visible indique l'environnement de preprod.
- Aucune donnée client réelle en preprod ; les jeux de données viennent de `bin/seed.php`.

## 8. Sauvegardes et journaux

- Base sauvegardée par le mécanisme homelab existant (`HOMELAB/backup/`) — à raccorder lors
  de la mise en preprod.
- `storage/uploads/` contient les **originaux** des œuvres en haute définition : c'est la
  donnée irremplaçable du projet. Sauvegarde distincte de celle de la base, vérifiée par
  une restauration d'essai avant la mise en production.
- Journaux applicatifs en rotation quotidienne, conservés 30 jours, purgés des données
  personnelles (voir `06-securite.md` §9).

## 9. Points à confirmer `@decision`

1. **Le domaine de production final** : `cedrictaldu.com` sur mutualisé, ou maintien sur
   `customer.phracktale.com/cedric-taldu` ? La réponse change la stratégie de référencement
   (un sous-chemin d'un domaine tiers est très défavorable pour le SEO d'un site d'artiste)
   et le contenu des mentions légales. **Recommandation : preprod sur Thor, production sur
   un domaine propre.**
2. Réservation effective des ports 18120 / 13306 / 28083 / 8027 dans la table de routage.
3. Emplacement du dépôt : `HOMELAB_CUSTOMERS/` avec un dossier de référence dans
   `HOMELAB/_CUSTOMERS/cedric-taldu/` (CLAUDE.md + vhost), comme Ateya et Planète Découverte.
