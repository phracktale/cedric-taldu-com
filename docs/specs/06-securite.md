# 06 — Sécurité : garde-fous obligatoires

Chaque règle de ce document est **testée automatiquement**. Une règle sans test n'est pas
implémentée. La correspondance règle ↔ test figure dans `07-tests-tdd.md`.

Modèle de menace retenu : site public à faible trafic mais **encaissant des paiements** et
**détenant des données personnelles d'acheteurs**. Les attaquants réalistes sont les robots
d'exploitation de masse, le spam de formulaire, la fraude au prix, et la compromission du
compte administrateur unique.

## 1. Injection SQL

- PDO exclusivement, `ATTR_EMULATE_PREPARES = false`, `ERRMODE_EXCEPTION`.
- **Toute** valeur variable est un paramètre lié. Aucune interpolation, aucune
  concaténation, sans exception.
- Les identifiants dynamiques (colonne de tri, sens) passent par une liste blanche en dur.
- Le SQL n'existe que dans `src/Repository/` — vérifié par analyse du code source.
- L'utilisateur MySQL applicatif n'a que `SELECT, INSERT, UPDATE, DELETE` sur la base du
  site. Pas de `DROP`, `CREATE`, `FILE`, ni accès à d'autres bases. Les migrations
  utilisent un compte distinct, employé uniquement au déploiement.
- Les messages d'erreur SQL ne sortent jamais vers le navigateur.

## 2. XSS

- Échappement **à la sortie**, systématique, via `e()`, `attr()`, `jsonAttr()`.
  Analyse statique des templates : tout `<?=` doit appeler un helper autorisé.
- HTML riche (blog, descriptions) : liste blanche stricte de balises et d'attributs,
  assainissement **à l'écriture**, stockage de la version assainie.
- `href` limité aux schémas `https`, `mailto` et aux chemins internes. `javascript:`,
  `data:` et `vbscript:` sont rejetés, y compris sous forme obfusquée.
- JSON-LD produit par `json_encode` avec `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
  JSON_HEX_QUOT`, jamais par concaténation.
- **CSP stricte, avec nonce** :
  ```
  Content-Security-Policy:
    default-src 'self';
    script-src 'self' 'nonce-<aléatoire>';
    style-src 'self' 'nonce-<aléatoire>';
    img-src 'self' data:;
    font-src 'self';
    connect-src 'self';
    form-action 'self' https://checkout.stripe.com;
    frame-ancestors 'none';
    base-uri 'none';
    object-src 'none';
    upgrade-insecure-requests
  ```
  Aucun `unsafe-inline`, aucun `unsafe-eval`. Le nonce est régénéré à chaque réponse
  (`random_bytes(16)`). Les `onclick` inline des maquettes sont donc supprimés.
- Autres en-têtes sur **toutes** les réponses :
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=()`,
  `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`,
  et `Strict-Transport-Security: max-age=31536000; includeSubDomains` en production.

## 3. CSRF

- Jeton par session, 32 octets, comparé par `hash_equals`.
- Obligatoire sur **toutes** les méthodes non-GET. La table de routes est parcourue par un
  test : toute route POST/PUT/DELETE non explicitement exemptée doit rejeter une requête
  sans jeton valide.
- Seule exemption : `/webhooks/stripe`, protégé par signature cryptographique.
- Cookies : `HttpOnly`, `Secure`, `SameSite=Lax`, `path` = préfixe de l'application,
  noms préfixés `ct_` pour ne pas entrer en collision avec les autres applications de
  `customer.phracktale.com`.
- Le jeton est régénéré à la connexion et à la déconnexion.
- Les actions destructrices du back-office demandent une confirmation explicite, jamais par
  simple lien GET.

## 4. Authentification et sessions

- Argon2id, verrouillage progressif, réponses et durées indistinguables entre compte
  inexistant et mot de passe erroné.
- Session : `session.cookie_httponly=1`, `cookie_secure=1`, `cookie_samesite=Lax`,
  `use_strict_mode=1`, `use_only_cookies=1`, identifiant régénéré à l'élévation de
  privilège, `save_path` dans `storage/sessions` (hors webroot).
- Inactivité 30 min, durée absolue 12 h, empreinte faible (user-agent + réseau /24) vérifiée
  pour détecter un vol de session grossier — sans bloquer un changement d'IP légitime.
- Pas de « se souvenir de moi » sur le back-office.
- Aucun compte client public : rien à voler côté acheteur.

## 5. Téléversement de fichiers

C'est la surface la plus dangereuse du projet. Toutes les règles s'appliquent :

1. Types acceptés : `image/jpeg`, `image/png`, `image/webp` uniquement. **SVG interdit**
   (vecteur de XSS). PDF autorisé uniquement pour le livret, par un chemin séparé et
   réservé au rôle `admin`.
2. Le type est déterminé par `finfo_file` **et** `getimagesize`, jamais par l'extension ni
   par le `Content-Type` envoyé par le client.
3. Taille max 25 Mo, dimensions max 12 000 × 12 000 px, contrôle du nombre de pixels avant
   traitement pour éviter la « bombe de décompression ».
4. **Ré-encodage systématique par GD** : l'image est décodée puis réécrite. Cela détruit
   toute charge utile embarquée (polyglotte GIFAR, PHP en commentaire EXIF) et supprime les
   métadonnées, y compris la géolocalisation.
5. Nom de fichier aléatoire (`bin2hex(random_bytes(16))`), extension déduite du type réel.
   Le nom d'origine n'est conservé que comme métadonnée affichée, échappée.
6. Originaux stockés **hors webroot** (`storage/uploads/`), en arborescence à deux niveaux.
   Seuls les dérivés régénérés sont publics.
7. `public/media/` et `storage/` portent un `.htaccess` interdisant l'exécution PHP
   (`php_flag engine off`, `RemoveHandler`, `SetHandler none`) — ceinture et bretelles avec
   le point 6.
8. `X-Content-Type-Options: nosniff` sur les fichiers servis.
9. Le PDF du livret est servi par un contrôleur PHP avec
   `Content-Disposition: attachment` et `Content-Type: application/pdf`, à partir d'un
   identifiant, jamais d'un chemin fourni par le client.

## 6. Anti-spam

Sur le formulaire de contact, le formulaire de question rattaché à une œuvre, et le tunnel
de commande :

1. **Honeypot** : champ masqué en CSS (jamais `type=hidden`, jamais `display:none` seul —
   positionnement hors écran + `aria-hidden` + `autocomplete="off"` + `tabindex="-1"`).
   Rempli → rejet **silencieux** avec une réponse de succès, pour ne pas informer le robot.
2. **Horodatage signé** : un champ contient l'instant de génération du formulaire, signé
   par HMAC. Soumission en **moins de 3 secondes** ou après **plus de 2 heures** → rejet.
3. **Limitation de débit** (table `rate_limits`, fenêtre glissante) :
   | Action | Limite |
   |---|---|
   | Contact / question | 3 par heure et par IP, 10 par jour |
   | Connexion admin | 10 par 15 min et par IP |
   | Création de session Stripe | 10 par heure et par IP |
   | Ajout au panier | 60 par heure et par IP |
   La clé est `SHA-256(portée + IP + poivre)` : l'IP en clair n'est jamais stockée.
4. **Heuristiques** contribuant à `spam_score` : plus de 2 URL dans le message, message
   entièrement en majuscules, absence totale de caractères accentués sur un formulaire FR,
   corps identique à un message reçu dans les 24 h, alphabet incohérent avec la langue.
   Au-delà d'un seuil : message stocké avec le statut `spam`, sans notification.
5. **Aucun CAPTCHA au départ.** Si le spam passe malgré tout, ajout de Cloudflare Turnstile
   — mais il implique une origine tierce dans la CSP et une mention RGPD, donc décision
   explicite. L'interface `SpamGuard` prévoit le point d'extension.
6. **Injection d'en-têtes de messagerie** : toute valeur entrant dans un e-mail est purgée
   de `\r` et `\n` ; les adresses sont validées par `FILTER_VALIDATE_EMAIL` ; les en-têtes
   sont construits par la bibliothèque, jamais concaténés. Le champ « sujet » du site est
   fixe côté serveur, celui de l'utilisateur va dans le corps.

## 7. Paiement

- Le total envoyé à Stripe est **recalculé côté serveur** à la création de la session.
- Le statut `paid` est atteignable uniquement par le webhook signé. Aucune autre voie.
- Vérification de signature sur le **corps brut**, avec tolérance temporelle par défaut.
- Idempotence par `stripe_events.event_id` en clé primaire.
- Machine à états explicite pour les commandes ; toute transition non prévue lève une
  exception et est journalisée.
- Verrouillage de ligne (`SELECT … FOR UPDATE`) sur les œuvres et les stocks.
- Les clés Stripe vivent dans `.env`. Un contrôle au démarrage interdit une clé de test en
  production et une clé de production hors production.
- `form-action` de la CSP autorise `https://checkout.stripe.com` et rien d'autre.

## 8. Contrôle d'accès

- Toute route `/admin/*` passe par `AuthGuard` : un test parcourt la table de routes et
  vérifie qu'aucune route d'administration n'est accessible sans session valide.
- Contrôle par rôle sur les commandes, les réglages et les utilisateurs.
- **Références directes d'objet** : le back-office est mono-utilisateur, mais les jetons
  publics (consultation de commande, aperçu, téléchargement) sont aléatoires sur 32 octets,
  comparés en temps constant, et à durée de vie limitée pour les aperçus.
- Pas d'énumération : `/fr/oeuvre/{slug}` d'une œuvre non publiée renvoie 404, pas 403.

## 9. Données personnelles et RGPD

- Données collectées : identité, e-mail, téléphone facultatif, adresses, historique de
  commande, messages de contact. Aucune donnée sensible, aucun profilage.
- **IP jamais stockée en clair** : `SHA-256(IP + poivre)` uniquement, pour la limitation de
  débit et l'anti-spam. Poivre dans `.env`.
- Durées de conservation, appliquées par la tâche cron :
  | Donnée | Durée |
  |---|---|
  | Commandes et factures | 10 ans (obligation comptable) |
  | Messages de contact traités | 3 ans |
  | Messages indésirables | 90 jours |
  | Paniers abandonnés | 60 jours |
  | Empreintes d'IP | 12 mois |
  | Journaux applicatifs | 30 jours |
  | Journal d'audit | 3 ans |
- Pas de cookie non essentiel → **pas de bannière de consentement**, mais une page
  « Confidentialité » décrivant les cookies fonctionnels (session, panier, langue) et les
  droits d'accès, de rectification et d'effacement.
- Aucune ressource tierce chargée : polices auto-hébergées, aucune carte embarquée, aucun
  outil de mesure d'audience à ce stade.
- Procédure d'effacement sur demande : anonymisation de la commande (identité et adresses
  remplacées) en conservant les montants pour la comptabilité. Fonction dédiée et testée.

## 10. Exploitation

- `display_errors=0`, `log_errors=1` en production. La page 500 affiche un identifiant de
  corrélation et rien d'autre.
- Aucun `phpinfo()`, aucun fichier de test, aucun `.bak`, aucun dépôt Git servi.
  `.htaccess` interdit `.env`, `.git`, `composer.*`, `*.md`, `*.sql`, `storage/`, `src/`,
  `vendor/`, `migrations/`, `tests/`, `docker/`.
- Listing de répertoires désactivé, `ServerSignature Off`.
- HTTPS forcé, HSTS en production. En preprod, TLS terminé par Heimdall : le site doit
  détecter `X-Forwarded-Proto` **uniquement** depuis le proxy de confiance.
- Sauvegardes chiffrées, restauration testée avant mise en production.
- Journalisation des événements de sécurité : échecs de connexion, rejets CSRF, signatures
  de webhook invalides, dépassements de limite, uploads refusés.

## 11. Dépendances

- Deux dépendances de production seulement (`stripe/stripe-php`, `phpmailer/phpmailer`),
  épinglées par `composer.lock`.
- `composer audit` exécuté en intégration continue ; une vulnérabilité connue fait échouer
  la construction.
- `vendor/` est commité : toute mise à jour passe par une revue de la différence, jamais
  par un `composer update` aveugle.

## 12. Revue avant chaque mise en production

- [ ] `composer test` vert, dont la suite `security` entière
- [ ] `composer audit` sans vulnérabilité
- [ ] Aucun secret dans le dépôt (`git log -p` filtré sur les motifs de clés)
- [ ] En-têtes vérifiés sur l'environnement cible (pas seulement en test)
- [ ] Webhook Stripe testé de bout en bout en mode test
- [ ] Sauvegarde base + `storage/uploads/` vérifiée par une restauration
- [ ] Compte administrateur avec mot de passe fort et 2FA activée
- [ ] Mentions légales, CGV et politique de confidentialité publiées et à jour
