<?php

declare(strict_types=1);

namespace APress\Core\Extension;

use APress\Core\ClockInterface;
use APress\Core\LoggerInterface;
use APress\Core\LogLevel;
use Throwable;

/**
 * Découvre les extensions d'un dossier, les active, les désactive et démarre
 * celles qui sont actives.
 *
 * Garanties :
 * - désactiver ne supprime jamais de données : l'état passe à inactif, les
 *   tables restent, et une réactivation ne rejoue que les migrations nouvelles ;
 * - une extension active mais cassée (dossier disparu, classe absente,
 *   incompatible après une mise à jour du cœur) est ignorée et journalisée :
 *   elle ne fait jamais tomber le site ;
 * - une activation dont les migrations échouent n'enregistre aucun état.
 */
final class ExtensionManager
{
    /** @var callable(Manifest): void */
    private $migrate;

    /**
     * Préfixe de namespace => dossier, pour l'autoloader. STATIQUE : un seul
     * autoloader par processus, qui ne retient aucune instance. Un autoloader
     * par gestionnaire retiendrait chaque conteneur construit, et un processus
     * qui sert de nombreuses requêtes (suite de tests, worker) épuiserait sa
     * mémoire.
     *
     * @var array<string, string>
     */
    private static array $autoload = [];

    private static bool $autoloaderRegistered = false;

    /**
     * @param callable(Manifest): void $migrate applique les migrations en attente de l'extension
     */
    public function __construct(
        private readonly string $directory,
        private readonly ExtensionStateStore $store,
        callable $migrate,
        private readonly string $coreVersion,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->migrate = $migrate;
    }

    /**
     * Toutes les extensions connues, du disque ou de la base, triées par nom.
     *
     * @return list<ExtensionInfo>
     */
    public function all(): array
    {
        $states = $this->store->all();
        $infos = [];

        foreach ($this->discover() as $name => $result) {
            $manifest = $result instanceof Manifest ? $result : null;
            $error = $result instanceof Manifest ? $this->incompatibility($result) : $result;
            $infos[$name] = new ExtensionInfo($name, $manifest, $states[$name] ?? null, $error);
        }

        foreach ($states as $name => $state) {
            if (!array_key_exists($name, $infos)) {
                $infos[$name] = new ExtensionInfo($name, null, $state, 'Le dossier de l\'extension est introuvable.');
            }
        }

        ksort($infos);

        return array_values($infos);
    }

    public function activate(string $name): void
    {
        $manifest = $this->manifest($name);

        $incompatibility = $this->incompatibility($manifest);
        if ($incompatibility !== null) {
            throw new ExtensionRefused($incompatibility);
        }

        $states = $this->store->all();
        $missing = array_values(array_filter(
            $manifest->requiredExtensions,
            static fn (string $dependency): bool => !(isset($states[$dependency]) && $states[$dependency]->active),
        ));
        if ($missing !== []) {
            throw new ExtensionRefused(sprintf(
                '« %s » nécessite d\'abord l\'activation de : %s.',
                $manifest->label,
                implode(', ', $missing),
            ));
        }

        ($this->migrate)($manifest);

        $this->store->save(new ExtensionState($name, true, $manifest->version, $this->now()));
    }

    public function deactivate(string $name): void
    {
        $states = $this->store->all();
        $state = $states[$name] ?? null;
        if ($state === null || !$state->active) {
            throw new ExtensionRefused(sprintf('L\'extension « %s » n\'est pas active.', $name));
        }

        $dependents = [];
        foreach ($this->discover() as $other => $result) {
            if (
                $result instanceof Manifest
                && ($states[$other] ?? null)?->active
                && in_array($name, $result->requiredExtensions, true)
            ) {
                $dependents[] = $other;
            }
        }
        if ($dependents !== []) {
            throw new ExtensionRefused(sprintf(
                'Désactivez d\'abord les extensions qui dépendent de « %s » : %s.',
                $name,
                implode(', ', $dependents),
            ));
        }

        $this->store->save(new ExtensionState($name, false, $state->version, $this->now()));
    }

    /**
     * Enregistre les extensions actives et saines dans les points d'accroche.
     *
     * @return list<string> noms des extensions démarrées
     */
    public function boot(Hooks $hooks): array
    {
        try {
            $states = $this->store->all();
        } catch (Throwable $exception) {
            // Table core_extensions absente (migrations du cœur pas encore
            // jouées) ou base indisponible : le site démarre sans extensions
            // plutôt que de tomber.
            $this->logger->log(LogLevel::Error, 'État des extensions illisible, aucune extension démarrée.', [
                'raison' => $exception->getMessage(),
            ]);

            return [];
        }

        $discovered = $this->discover();
        $booted = [];

        foreach ($states as $name => $state) {
            if (!$state->active) {
                continue;
            }

            $manifest = $discovered[$name] ?? 'dossier introuvable';
            if (!$manifest instanceof Manifest) {
                $this->skip($name, $manifest);
                continue;
            }

            $incompatibility = $this->incompatibility($manifest);
            if ($incompatibility !== null) {
                $this->skip($name, $incompatibility);
                continue;
            }

            try {
                $this->registerAutoload($manifest);
                $entry = $manifest->entry;
                if (!class_exists($entry) || !is_subclass_of($entry, ExtensionInterface::class)) {
                    $this->skip($name, sprintf('classe d\'entrée %s absente ou invalide', $entry));
                    continue;
                }

                // Les déclarations sont faites dans une copie : une extension qui
                // lève une exception au milieu de register() n'en laisse aucune.
                $own = new Hooks();
                (new $entry())->register($own);
                $this->merge($own, $hooks);
                $hooks->markBooted($name);
                $booted[] = $name;
            } catch (Throwable $exception) {
                $this->skip($name, $exception->getMessage());
            }
        }

        return $booted;
    }

    private function manifest(string $name): Manifest
    {
        $result = $this->discover()[$name] ?? null;

        if ($result === null) {
            throw new ExtensionRefused('Extension inconnue.');
        }

        if (!$result instanceof Manifest) {
            throw new ExtensionRefused($result);
        }

        return $result;
    }

    /**
     * Dossiers contenant un extension.json. Le nom du dossier fait foi : il est
     * contrôlé avant tout accès, et le manifeste doit porter le même.
     *
     * @return array<string, Manifest|string> manifeste, ou message d'erreur
     */
    private function discover(): array
    {
        $found = [];
        $entries = is_dir($this->directory) ? (scandir($this->directory) ?: []) : [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry) !== 1) {
                continue;
            }
            $file = $this->directory . '/' . $entry . '/' . Manifest::FILE;
            if (!is_file($file)) {
                continue;
            }

            try {
                $found[$entry] = Manifest::fromFile($file);
            } catch (InvalidManifest $exception) {
                $found[$entry] = 'Manifeste invalide : ' . $this->withoutPath($exception->getMessage());
            }
        }

        ksort($found);

        return $found;
    }

    private function incompatibility(Manifest $manifest): ?string
    {
        if (VersionConstraint::satisfies($this->coreVersion, $manifest->coreConstraint)) {
            return null;
        }

        return sprintf(
            '« %s » exige le cœur A-Press %s ; version installée : %s.',
            $manifest->label,
            $manifest->coreConstraint,
            $this->coreVersion,
        );
    }

    private function registerAutoload(Manifest $manifest): void
    {
        foreach ($manifest->autoload as $prefix => $directory) {
            self::$autoload[$prefix] = $directory;
        }

        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            foreach (self::$autoload as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        });
        self::$autoloaderRegistered = true;
    }

    private function merge(Hooks $from, Hooks $into): void
    {
        foreach ($from->routes() as $route) {
            $into->addRoute($route);
        }
        foreach ($from->adminMenu() as $item) {
            $into->addAdminMenuItem($item);
        }
        foreach ($from->services() as $id => $factory) {
            $into->addService($id, $factory);
        }
    }

    private function skip(string $name, string $reason): void
    {
        $this->logger->log(LogLevel::Error, 'Extension active non démarrée.', [
            'extension' => $name,
            'raison' => $this->withoutPath($reason),
        ]);
    }

    /**
     * Les messages remontent jusqu'à la page d'administration : on retire le
     * chemin serveur que porte InvalidManifest.
     */
    private function withoutPath(string $message): string
    {
        return (string) preg_replace('/^Manifeste invalide \([^)]*\) : /', '', $message);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
