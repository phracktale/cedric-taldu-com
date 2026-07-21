<?php

/**
 * Creation d'un compte d'administration.
 *
 * Usage :
 *   php bin/create-admin.php --email=artiste@example.com --nom="Cédric Taldu"
 *   php bin/create-admin.php --email=... --nom=... --role=editor
 *   php bin/create-admin.php --email=... --nom=... --mot-de-passe=...
 *
 * Sans compte, personne n'entre : c'est le seul point d'amorcage du back-office,
 * et il n'y a AUCUNE inscription publique (04-back-office, en-tete).
 *
 * Trois choix de securite valent d'etre expliques :
 *
 *  1. Le mot de passe est ENGENDRE par defaut, et affiche une seule fois. Un mot
 *     de passe choisi a la main sur un poste d'atelier est presque toujours plus
 *     faible que trente-deux caracteres aleatoires, et l'artiste le confie de
 *     toute facon a son gestionnaire de mots de passe.
 *  2. S'il est fourni, il l'est par --mot-de-passe et jamais par un prompt : le
 *     script tourne en deploiement, sans terminal interactif. La contrepartie
 *     est qu'il figure dans l'historique du shell — le script le dit.
 *  3. Le compte applicatif suffit : ce script n'a besoin d'aucun droit de
 *     schema, contrairement a bin/migrate.php.
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Domain\Admin\Role;
use App\Repository\UserRepository;
use App\Service\Auth\PasswordHasher;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var array<string, string> $systemEnvironment */
$systemEnvironment = array_filter(getenv(), 'is_string');
$env = Env::load($root . '/.env', $systemEnvironment);

/**
 * @param  list<string> $argv
 * @return array<string, string>
 */
$parseOptions = static function (array $argv): array {
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '1';
    }

    return $options;
};

/** @var list<string> $argv */
$options = $parseOptions($argv);

$email = trim($options['email'] ?? '');
$displayName = trim($options['nom'] ?? '');
$roleName = trim($options['role'] ?? Role::Admin->value);

if ($email === '' || $displayName === '') {
    fwrite(STDERR, <<<'TXT'
        Usage : php bin/create-admin.php --email=<adresse> --nom=<nom affiché> [--role=admin|editor]
                                         [--mot-de-passe=<mot de passe>]

        Sans --mot-de-passe, un mot de passe fort est engendré et affiché une seule fois.

        TXT);

    exit(1);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, 'Adresse électronique invalide : ' . $email . PHP_EOL);

    exit(1);
}

$role = Role::tryFrom($roleName);

if ($role === null) {
    fwrite(STDERR, 'Rôle inconnu : ' . $roleName . ' (attendu : admin ou editor)' . PHP_EOL);

    exit(1);
}

// Mot de passe engendre par defaut : trente-deux caracteres hexadecimaux, soit
// 128 bits d'entropie. Recopiable a la main si necessaire, et sans ambiguite de
// lecture — pas de « l » contre « 1 », pas de « O » contre « 0 ».
$generated = !isset($options['mot-de-passe']);
$password = $generated ? bin2hex(random_bytes(16)) : (string) $options['mot-de-passe'];

if (strlen($password) < 12) {
    fwrite(STDERR, 'Le mot de passe doit faire au moins douze caractères.' . PHP_EOL);

    exit(1);
}

try {
    $pdo = Database::fromEnv($env)->connect();
    $users = new UserRepository($pdo);

    if ($users->findByEmail($email) !== null) {
        fwrite(STDERR, 'Un compte existe déjà pour ' . $email . PHP_EOL);

        exit(1);
    }

    $id = $users->create(
        $email,
        (new PasswordHasher())->hash($password),
        $displayName,
        $role,
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
} catch (Throwable $exception) {
    // Le message du moteur peut porter un nom d'hote et un nom d'utilisateur :
    // il va sur la sortie d'erreur d'un script d'administration, jamais vers un
    // navigateur (06-securite §10).
    fwrite(STDERR, 'Échec de la création : ' . $exception->getMessage() . PHP_EOL);

    exit(1);
}

echo 'Compte créé.', PHP_EOL;
echo '  identifiant : ', $id, PHP_EOL;
echo '  adresse     : ', $email, PHP_EOL;
echo '  rôle        : ', $role->label(), PHP_EOL;

if ($generated) {
    echo PHP_EOL;
    echo '  mot de passe : ', $password, PHP_EOL;
    echo PHP_EOL;
    echo 'Ce mot de passe n’est affiché qu’une fois : il n’est stocké qu’en empreinte Argon2id.', PHP_EOL;
}

echo PHP_EOL;
// Rappel sans lequel la 2FA resterait une curiosite : elle est optionnelle,
// mais la checklist de mise en production l'exige.
echo 'Activez ensuite le double facteur depuis /admin/compte/2fa ', PHP_EOL;
echo '(06-securite §12 : « Compte administrateur avec mot de passe fort et 2FA activée »).', PHP_EOL;
