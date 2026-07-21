# Protocole de test — `tests/`

Ce projet est développé **en test-first**. La règle est simple et sans exception :
*si vous écrivez une ligne de `src/` sans avoir vu un test échouer pour cette ligne,
vous êtes hors process.*

## Cycle imposé

1. **RED** — écrire le test le plus petit qui exprime le comportement attendu. L'exécuter.
   Vérifier qu'il échoue **pour la bonne raison** (assertion non satisfaite, pas erreur de
   syntaxe ou classe introuvable non intentionnelle).
2. **GREEN** — écrire le minimum de code pour le faire passer. Pas d'anticipation, pas de
   généralisation spéculative.
3. **REFACTOR** — nettoyer sous couverture verte. Aucun changement de comportement.
4. Passer au cas suivant : cas nominal, puis limites, puis erreurs, puis sécurité.

Les commits suivent ce cycle : `test(panier): ligne dupliquée incrémente la quantité`
puis `feat(panier): fusion des lignes identiques`. Un commit `feat` sans `test`
correspondant dans la même branche est un défaut de process.

## Les quatre suites

| Suite | Périmètre | Base de données | Cible de durée |
|---|---|---|---|
| `unit` | `Domain/`, `Core/` purs, calculs, machines à états, validation | **non** | < 2 s |
| `integration` | `Repository/`, migrations, transactions, contraintes SQL | oui (base de test) | < 20 s |
| `functional` | `Kernel::handle(Request)` → `Response` : routes, statuts, HTML rendu, redirections | oui | < 60 s |
| `security` | garde-fous automatisés (voir plus bas) | partiellement | < 15 s |

Aucun test ne démarre de serveur HTTP ni n'appelle le réseau. Stripe, SMTP, l'horloge et le
système de fichiers sont doublés.

## Règles d'écriture

- **Un comportement par test.** Le nom du test est une phrase française qui décrit la règle
  métier : `public function test_une_oeuvre_vendue_ne_peut_pas_etre_ajoutee_au_panier()`.
- **Arrange / Act / Assert** séparés par une ligne vide. Pas d'assertion dans la phase
  d'arrangement.
- **Données par factories**, pas par insertion SQL à la main :
  `ArtworkFactory::make()->available()->priced(45000)->create()`.
- Chaque test d'intégration ou fonctionnel s'exécute dans une **transaction annulée** en
  `tearDown`. Aucun test ne dépend de l'ordre d'exécution ni de l'état laissé par un autre.
- L'horloge est toujours `FrozenClock` en test. Un test qui dépend de `time()` réel est un
  test instable, donc un défaut.
- Les assertions sur le HTML portent sur la **structure** (sélecteur, présence d'un
  attribut, valeur d'un champ), pas sur des chaînes de mise en forme fragiles.

## Suite `security` — obligatoire et bloquante

Ces tests appliquent mécaniquement les règles de `docs/specs/06-securite.md`. Ils tournent
sur le code source lui-même autant que sur les réponses HTTP.

| Test | Ce qu'il vérifie |
|---|---|
| `SqlInjectionTest` | Pour chaque champ texte de chaque formulaire public et admin, injecte une charge SQL et vérifie que la donnée est stockée telle quelle et qu'aucune erreur SQL ne fuit |
| `SqlLocationTest` | Aucun mot-clé SQL hors de `src/Repository/` ; aucune interpolation de variable dans une chaîne SQL |
| `XssTest` | Injecte `<script>`, `"><img onerror>`, `javascript:` dans chaque champ persistable, puis vérifie l'échappement dans **toutes** les pages qui le réaffichent (public et back-office) |
| `EscapingTest` | Scanne `templates/` : tout `<?=` doit appeler un helper d'échappement autorisé |
| `CsrfTest` | Parcourt la table de routes : chaque route non-GET (hors webhook Stripe) rejette une requête sans jeton valide avec un 419/403 |
| `SuperglobalTest` | Aucun accès aux superglobales hors `Core\Request` |
| `HeadersTest` | Chaque réponse porte CSP, `X-Content-Type-Options`, `Referrer-Policy`, `frame-ancestors`, `Permissions-Policy` ; aucun script inline sans nonce |
| `AuthTest` | Chaque route `/admin/*` sans session valide redirige vers la connexion ; verrouillage après N échecs ; régénération de session à la connexion |
| `UploadTest` | Rejet des fichiers non-images, du PHP déguisé en JPEG (magic bytes + polyglotte GIF/PHP), du SVG, des fichiers hors limite de taille et de dimensions ; vérifie le ré-encodage et la suppression des métadonnées EXIF |
| `SpamTest` | Honeypot rempli → rejet silencieux ; soumission en moins de 3 s → rejet ; N soumissions par IP et par heure → limitation ; CRLF dans le champ e-mail → rejet |
| `PriceIntegrityTest` | Un panier dont le prix a été modifié côté client aboutit à une commande au prix de la base ; une session Stripe est créée avec les montants recalculés |
| `MoneyTypeTest` | Aucun calcul monétaire en flottant dans `src/` |
| `PathTraversalTest` | `../`, chemins absolus et octets nuls dans tout paramètre servant à nommer ou lire un fichier |
| `ErrorLeakTest` | En mode production, une exception forcée ne renvoie ni trace, ni SQL, ni chemin serveur |
| `ExposureTest` | `/storage/*`, `/src/*`, `/.env`, `/.git/*`, `/vendor/*`, `/migrations/*` ne sont pas servis |
| `WebhookTest` | Signature Stripe invalide → 400 sans effet ; rejeu du même événement → aucun double effet ; corps brut utilisé pour la vérification |

## Seuils de couverture (bloquants en CI)

| Périmètre | Seuil |
|---|---|
| `src/Domain/` | 95 % lignes, 90 % branches |
| `src/Service/` | 90 % lignes |
| `src/Repository/` | 85 % lignes |
| Global `src/` | 80 % lignes |

La couverture est un plancher de sécurité, pas un objectif : un module à 100 % dont les cas
d'erreur ne sont pas testés reste non conforme.

## Base de test

- Base dédiée `cedrictaldu_test`, créée par `bin/migrate.php --env=test`, **construite
  depuis les migrations** — jamais depuis un dump. Une migration qui casse la construction
  de la base de test casse la CI.
- Les migrations sont testées dans les deux sens quand une réversion est prévue.

## Définition de « terminé » pour une tâche

- [ ] Les tests décrivant le comportement existent et sont passés par un état rouge.
- [ ] Toute la suite est verte (`composer test`).
- [ ] PHPStan niveau 8 sans erreur, PSR-12 respecté.
- [ ] Les tests de sécurité pertinents pour la fonctionnalité ont été **ajoutés**, pas
      seulement exécutés.
- [ ] Aucun `TODO`, `dd()`, `var_dump`, `error_log` de débogage dans le code livré.
- [ ] Les seuils de couverture sont tenus.
