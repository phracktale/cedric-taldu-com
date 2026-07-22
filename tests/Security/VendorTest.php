<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Ce qui est commite dans vendor/ est ce qui TOURNE EN PRODUCTION.
 *
 * `composer install` n'est pas garanti sur l'hebergement mutualise
 * (CLAUDE.md) : le depot est le paquet de deploiement. Deux facons de casser
 * le site en production sans qu'aucun autre test ne s'en apercoive :
 *
 * 1. Committer des cartes d'autoload engendrees AVEC les dependances de
 *    developpement. `autoload_files.php` fait un `require` A CHAUD de ce qu'il
 *    liste : sur un serveur ou PHPUnit n'existe pas, la toute premiere requete
 *    echoue sur un fichier absent. Le site est mort, et le symptome ne designe
 *    rien de ce qu'on vient de changer.
 * 2. Oublier une dependance de production dans la liste blanche du .gitignore.
 *
 * Ces tests portent sur le contenu COMMITE, lu par `git show`, et non sur la
 * copie de travail : en local, `composer install` reintroduit legitimement les
 * dependances de developpement dans les cartes, et cela ne doit pas rendre la
 * suite rouge. C'est ce qui part sur le serveur qui compte.
 */
final class VendorTest extends TestCase
{
    /**
     * Repertoires de vendor/ suivis par git — la liste blanche du .gitignore.
     *
     * @var list<string>
     */
    private const WHITELIST = ['composer', 'stripe', 'phpmailer'];

    public function test_les_dependances_de_production_sont_commitees(): void
    {
        // Sans elles, le site n'a ni paiement ni courriel sur un serveur
        // depourvu de composer.
        $suivis = $this->fichiersSuivis();

        $this->assertNotSame([], preg_grep('#^vendor/stripe/#', $suivis), 'stripe/stripe-php doit être commité.');
        $this->assertNotSame([], preg_grep('#^vendor/phpmailer/#', $suivis), 'phpmailer doit être commité.');
        $this->assertContains('vendor/autoload.php', $suivis);
    }

    public function test_aucune_dependance_de_developpement_n_est_commitee(): void
    {
        // PHPUnit, PHPStan et PHP_CodeSniffer pesent 44 Mo et n'ont rien a
        // faire sur un mutualise.
        $intrus = [];

        foreach ($this->fichiersSuivis() as $chemin) {
            if (!str_starts_with($chemin, 'vendor/')) {
                continue;
            }

            $paquet = explode('/', $chemin)[1] ?? '';

            if ($paquet !== 'autoload.php' && !in_array($paquet, self::WHITELIST, true)) {
                $intrus[$paquet] = true;
            }
        }

        $this->assertSame(
            [],
            array_keys($intrus),
            'Dépendances hors liste blanche commitées dans vendor/.',
        );
    }

    public function test_l_autoload_commite_ne_charge_a_chaud_aucun_paquet_absent(): void
    {
        // LE test de ce fichier. autoload_files.php est `require`-e a chaud :
        // chaque entree doit designer un fichier qui existera sur le serveur.
        $contenu = $this->contenuCommite('vendor/composer/autoload_files.php');

        if ($contenu === null) {
            $this->markTestSkipped('vendor/composer/autoload_files.php n’est pas encore commité.');
        }

        $this->assertSame(
            [],
            $this->paquetsHorsListeBlanche($contenu),
            'autoload_files.php commité charge à chaud un paquet qui ne sera pas déployé. '
            . 'Lancez « composer dump:prod » avant de committer.',
        );
    }

    public function test_les_espaces_de_noms_commites_ne_designent_aucun_paquet_absent(): void
    {
        // Les entrees PSR-4 sont paresseuses, donc moins dangereuses — mais une
        // carte qui annonce des paquets absents rend tout diagnostic trompeur
        // le jour ou quelque chose casse vraiment.
        $contenu = $this->contenuCommite('vendor/composer/autoload_psr4.php');

        if ($contenu === null) {
            $this->markTestSkipped('vendor/composer/autoload_psr4.php n’est pas encore commité.');
        }

        $this->assertSame(
            [],
            $this->paquetsHorsListeBlanche($contenu),
            'autoload_psr4.php commité désigne un paquet qui ne sera pas déployé.',
        );
    }

    /**
     * Paquets cites par une carte d'autoload et absents de la liste blanche.
     *
     * @return list<string>
     */
    private function paquetsHorsListeBlanche(string $contenu): array
    {
        preg_match_all('#\$vendorDir \. \'/([^/\']+/[^/\']+)#', $contenu, $trouves);

        $intrus = [];

        foreach ($trouves[1] as $chemin) {
            $paquet = explode('/', $chemin)[0];

            if (!in_array($paquet, self::WHITELIST, true)) {
                $intrus[$paquet] = true;
            }
        }

        return array_keys($intrus);
    }

    /**
     * @return list<string>
     */
    private function fichiersSuivis(): array
    {
        $sortie = $this->git(['ls-files', '--', 'vendor']);

        if ($sortie === null) {
            $this->markTestSkipped('git indisponible : le contenu commité ne peut pas être inspecté.');
        }

        return array_values(array_filter(explode("\n", str_replace("\r", '', $sortie))));
    }

    private function contenuCommite(string $chemin): ?string
    {
        return $this->git(['show', 'HEAD:' . $chemin]);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): ?string
    {
        $racine = dirname(__DIR__, 2);

        $commande = 'git -C ' . escapeshellarg($racine);

        foreach ($arguments as $argument) {
            $commande .= ' ' . escapeshellarg($argument);
        }

        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open($commande, $descripteurs, $tuyaux);

        if (!is_resource($processus)) {
            return null;
        }

        $sortie = stream_get_contents($tuyaux[1]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);

        $code = proc_close($processus);

        return $code === 0 && is_string($sortie) ? $sortie : null;
    }
}
