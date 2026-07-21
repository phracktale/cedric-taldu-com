<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\RateLimitRepository;
use App\Service\Spam\RateLimiter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FrozenClock;

/**
 * 06-securite §6.3 : limitation de debit a fenetre glissante, cle
 * SHA-256(portee + IP + poivre) — « l'IP en clair n'est jamais stockee ».
 *
 * Le limiteur est teste CONTRE LA VRAIE BASE et non contre un double : ce qui
 * doit etre prouve, c'est que deux requetes concurrentes ne peuvent pas voir le
 * meme compteur, et cela ne se prouve pas contre un tableau en memoire.
 *
 * La limite du lot 2 est celle de la connexion d'administration : dix tentatives
 * par quart d'heure et par adresse.
 */
final class RateLimiterTest extends DatabaseTestCase
{
    private const POIVRE = 'poivre-de-test-suffisamment-long-pour-config';
    private const LIMITE = 10;
    private const FENETRE = 900;

    private FrozenClock $horloge;
    private RateLimiter $limiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->horloge = new FrozenClock();
        $this->limiteur = new RateLimiter(
            new RateLimitRepository($this->pdo),
            $this->horloge,
            self::POIVRE,
        );
    }

    public function test_les_premieres_tentatives_passent(): void
    {
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->assertTrue($this->tentative(), 'Tentative ' . $i);
        }
    }

    public function test_la_tentative_au_dela_de_la_limite_est_refusee(): void
    {
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative();
        }

        $this->assertFalse($this->tentative());
    }

    public function test_deux_adresses_ont_des_compteurs_independants(): void
    {
        // Sans cela, un seul robot fermerait le back-office a l'artiste.
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative('203.0.113.7');
        }

        $this->assertFalse($this->tentative('203.0.113.7'));
        $this->assertTrue($this->tentative('198.51.100.4'));
    }

    public function test_deux_portees_ont_des_compteurs_independants(): void
    {
        // La meme adresse peut atteindre sa limite de connexion sans perdre le
        // droit d'ajouter au panier.
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative(portee: 'auth.login');
        }

        $this->assertFalse($this->tentative(portee: 'auth.login'));
        $this->assertTrue($this->tentative(portee: 'cart.add'));
    }

    public function test_la_fenetre_glisse_avec_le_temps(): void
    {
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative();
        }
        $this->assertFalse($this->tentative());

        // Un quart d'heure plus tard, les tranches consommees sont sorties de
        // la fenetre : le droit revient, sans qu'aucune purge ait eu lieu.
        $this->horloge->advance('+16 minutes');

        $this->assertTrue($this->tentative());
    }

    public function test_la_fenetre_ne_glisse_pas_avant_l_heure(): void
    {
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative();
        }

        $this->horloge->advance('+14 minutes');

        $this->assertFalse($this->tentative());
    }

    public function test_les_tentatives_reparties_dans_la_fenetre_s_additionnent(): void
    {
        // Une tranche par minute : etaler les tentatives ne doit pas les faire
        // disparaitre, sinon la limite se contourne en attendant une minute.
        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative();
            $this->horloge->advance('+1 minute');
        }

        $this->assertFalse($this->tentative());
    }

    // -------------------------------------------------------------- vie privee

    public function test_l_adresse_n_est_jamais_stockee_en_clair(): void
    {
        $this->tentative('203.0.113.7');

        $statement = $this->pdo->query('SELECT bucket_key FROM rate_limits');
        $this->assertNotFalse($statement);

        /** @var list<string> $cles */
        $cles = $statement->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotSame([], $cles);

        foreach ($cles as $cle) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cle);
            $this->assertStringNotContainsString('203.0.113', $cle);
        }
    }

    public function test_deux_poivres_differents_donnent_deux_compteurs(): void
    {
        // Sans poivre, un attaquant qui connait l'adresse ciblee pourrait
        // recalculer la cle et lire le compteur dans un dump vole.
        $autre = new RateLimiter(
            new RateLimitRepository($this->pdo),
            $this->horloge,
            'un-autre-poivre-tout-aussi-long-que-le-premier',
        );

        for ($i = 1; $i <= self::LIMITE; $i++) {
            $this->tentative();
        }

        $this->assertFalse($this->tentative());
        $this->assertTrue($autre->allow('auth.login', '203.0.113.7', self::LIMITE, self::FENETRE));
    }

    // ------------------------------------------------------------------ purge

    public function test_la_purge_efface_les_tranches_hors_fenetre(): void
    {
        // 06-securite §9 : les empreintes d'IP se conservent douze mois. La
        // purge est idempotente et rejouable (CLAUDE.md, « pas de worker »).
        $this->tentative();
        $this->horloge->advance('+1 hour');

        $depot = new RateLimitRepository($this->pdo);
        $efface = $depot->purgeBefore($this->horloge->now()->modify('-30 minutes'));

        $this->assertSame(1, $efface);
        $this->assertSame(0, $this->compterTranches());
        $this->assertSame(0, $depot->purgeBefore($this->horloge->now()->modify('-30 minutes')));
    }

    // ------------------------------------------------------------------ outils

    private function tentative(string $ip = '203.0.113.7', string $portee = 'auth.login'): bool
    {
        return $this->limiteur->allow($portee, $ip, self::LIMITE, self::FENETRE);
    }

    private function compterTranches(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM rate_limits');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
