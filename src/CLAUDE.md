# Conventions de code — `src/`

Ces règles sont vérifiées par la suite `tests/Security` et par PHPStan. Elles ne sont pas
des préférences de style : chacune ferme une classe de vulnérabilité ou de régression.

## Règles absolues

### SQL
- Le SQL n'existe **que** dans `src/Repository/`. Un contrôleur, un service ou une classe
  de `Domain/` qui contient `SELECT`, `INSERT`, `UPDATE` ou `DELETE` fait échouer
  `SqlLocationTest`.
- **Toute** valeur variable passe par un paramètre lié. La concaténation ou l'interpolation
  d'une variable dans une chaîne SQL est interdite, sans exception.
- Les identifiants dynamiques (nom de colonne pour un tri, sens ASC/DESC) ne peuvent pas
  être liés : ils passent obligatoirement par une **liste blanche** en dur dans le dépôt.

```php
// INTERDIT
$sql = "SELECT * FROM artworks ORDER BY {$sort}";

// ATTENDU
private const SORTS = ['position' => 'position', 'recent' => 'created_at'];
$column = self::SORTS[$sort] ?? 'position';
$sql = "SELECT * FROM artworks WHERE category_id = :cat ORDER BY {$column} ASC";
$stmt = $this->pdo->prepare($sql);
$stmt->execute(['cat' => $categoryId]);
```

- PDO est construit une seule fois, dans `Core\Database`, avec :
  `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false`, `DEFAULT_FETCH_MODE = FETCH_ASSOC`,
  `STRINGIFY_FETCHES = false`, charset `utf8mb4`.
- Toute séquence multi-écritures qui doit rester cohérente (commande, décrément de stock,
  passage d'une œuvre à « vendu ») s'exécute dans une transaction avec verrouillage
  `SELECT ... FOR UPDATE` sur les lignes concernées.

### Échappement et sortie
- Les helpers de `Support/` sont les seuls moyens d'écrire une valeur dans un template :
  | Helper | Usage |
  |---|---|
  | `e($v)` | contenu texte HTML — `htmlspecialchars` `ENT_QUOTES\|ENT_SUBSTITUTE`, UTF-8 |
  | `attr($v)` | valeur d'attribut HTML |
  | `jsonAttr($v)` | données passées au JS via `data-*` — `json_encode` avec `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` |
  | `url($path, $params)` | construction d'URL interne, encodage des segments |
  | `money($cents, $locale)` | formatage monétaire, jamais de calcul |
- Aucune donnée issue de la base ou de l'utilisateur n'est écrite sans passer par un helper.
  `EscapingTest` scanne `templates/` : tout `<?=` dont l'expression n'est pas un appel à un
  helper autorisé fait échouer la suite.
- Le HTML riche du blog est **assaini à l'écriture**, pas à la lecture : le back-office
  passe le corps de l'article dans une liste blanche de balises et d'attributs, et stocke
  le résultat assaini. La lecture ne fait plus qu'afficher.

### Entrées
- Aucune lecture directe de `$_GET`, `$_POST`, `$_FILES`, `$_SERVER`, `$_COOKIE` en dehors
  de `Core\Request`. Test : `SuperglobalTest`.
- Toute entrée est validée par `Core\Validator` avec un schéma explicite avant usage.
  Pas d'affectation en masse : on nomme les champs attendus, un par un.
- Les identifiants d'URL sont typés (`int` positif ou slug validé par expression
  régulière `^[a-z0-9]+(?:-[a-z0-9]+)*$`) avant toute requête.

### Argent et stock
- `float` interdit pour l'argent. Un montant est un `Money` (entier de centimes + devise).
  Test : `MoneyTypeTest` scanne les opérateurs arithmétiques sur des variables nommées
  `*price*`, `*amount*`, `*total*`.
- Un prix affiché au client n'est jamais celui qu'il renvoie. À chaque étape du tunnel, le
  total est **recalculé** depuis la base.
- Le passage d'une œuvre originale à « vendu » et le décrément de stock d'une variante ne
  peuvent avoir lieu que dans le gestionnaire de webhook Stripe, en transaction, et sont
  idempotents.

### Secrets, fichiers, erreurs
- Aucun secret en dur. Tout passe par `Core\Env` ; l'absence d'une variable requise lève
  une exception au démarrage, elle n'est jamais silencieusement remplacée par une valeur
  par défaut.
- Aucun chemin de fichier construit à partir d'une entrée utilisateur. Les fichiers
  uploadés reçoivent un nom aléatoire (`bin2hex(random_bytes(16))`) et l'extension est
  déduite du type MIME détecté côté serveur, jamais du nom envoyé.
- En production : `display_errors=0`. Les exceptions sont journalisées avec un identifiant
  de corrélation et l'utilisateur voit une page 500 générique portant cet identifiant.
  Aucun message d'exception, trace, requête SQL ou chemin serveur n'atteint le navigateur.

## Structure des classes

- `declare(strict_types=1);` obligatoire.
- Injection par constructeur uniquement. Pas de singleton, pas de `static` porteur d'état,
  pas de variable globale. Un objet qui a besoin de l'heure reçoit un `ClockInterface`.
- `final` par défaut sur les classes concrètes ; on n'ouvre à l'héritage que sur besoin réel.
- Les exceptions du domaine sont typées (`ArtworkNotAvailable`, `InsufficientStock`,
  `InvalidOrderTransition`) et ne portent jamais de message destiné à l'affichage direct :
  le contrôleur les traduit.
- Toute méthode publique d'une classe de `Domain/` doit être testable sans base de données.
  Si ce n'est pas le cas, la logique est au mauvais endroit.

## Ce que l'on ne fait pas

- Pas de `eval`, `extract`, `create_function`, `unserialize` sur des données externes,
  `assert` avec chaîne, `preg_replace` avec modificateur `/e`.
- Pas d'appel à `mail()` : envoi SMTP authentifié via `MailerInterface`.
- Pas de `header('Location: ' . $input)` : les redirections utilisent une liste blanche de
  routes internes ou `RedirectResponse::toRoute()`.
- Pas de `@` pour masquer une erreur.
