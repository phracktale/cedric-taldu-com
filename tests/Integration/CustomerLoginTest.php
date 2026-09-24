<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\CustomerLoginRepository;
use App\Service\Account\CustomerLogin;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FrozenClock;

/**
 * Connexion à l'espace client par lien à usage unique (revue du 2026-09-24).
 */
final class CustomerLoginTest extends DatabaseTestCase
{
    private FrozenClock $horloge;
    private CustomerLogin $connexion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->horloge = new FrozenClock('2026-09-24 10:00:00');
        $this->connexion = new CustomerLogin(new CustomerLoginRepository($this->pdo), $this->horloge);
    }

    public function test_un_lien_ouvre_la_session_de_son_adresse_une_seule_fois(): void
    {
        $jeton = $this->connexion->issue('Camille@Example.com ');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $jeton);
        $this->assertSame('camille@example.com', $this->connexion->consume($jeton));
        $this->assertNull($this->connexion->consume($jeton));
    }

    public function test_le_jeton_n_est_jamais_stocke_en_clair(): void
    {
        $jeton = $this->connexion->issue('camille@example.com');

        $stocke = (string) $this->pdo->query('SELECT token_hash FROM customer_login_tokens')->fetchColumn();

        $this->assertNotSame($jeton, $stocke);
        $this->assertSame(hash('sha256', $jeton), $stocke);
    }

    public function test_un_lien_expire_au_bout_de_vingt_minutes(): void
    {
        $jeton = $this->connexion->issue('camille@example.com');

        $this->horloge->advance('+21 minutes');

        $this->assertNull($this->connexion->consume($jeton));
    }

    public function test_un_jeton_inconnu_ou_malforme_ne_donne_rien(): void
    {
        $this->assertNull($this->connexion->consume(str_repeat('a', 64)));
        $this->assertNull($this->connexion->consume('nimporte-quoi'));
    }
}
