<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Payment;

use App\Service\Payment\StripeCheckoutGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 06-securite §7 : « un controle au demarrage interdit une cle de test en
 * production et une cle de production hors production ».
 *
 * Les deux erreurs sont graves et symetriques : une cle de production en
 * preprod encaisse de vrais paiements pendant une recette, une cle de test en
 * production n'encaisse rien du tout pendant que le site annonce des commandes
 * payees.
 */
#[CoversClass(StripeCheckoutGateway::class)]
final class StripeKeyEnvironmentTest extends TestCase
{
    public function test_une_cle_de_production_en_production_est_acceptee(): void
    {
        StripeCheckoutGateway::assertKeyMatchesEnvironment('sk_live_abc', 'prod');

        $this->addToAssertionCount(1);
    }

    public function test_une_cle_de_test_hors_production_est_acceptee(): void
    {
        StripeCheckoutGateway::assertKeyMatchesEnvironment('sk_test_abc', 'preprod');
        StripeCheckoutGateway::assertKeyMatchesEnvironment('sk_test_abc', 'local');

        $this->addToAssertionCount(2);
    }

    public function test_une_cle_de_production_en_preprod_est_refusee(): void
    {
        // Le cas le plus couteux : une recette client qui encaisse pour de vrai.
        $this->expectException(RuntimeException::class);

        StripeCheckoutGateway::assertKeyMatchesEnvironment('sk_live_abc', 'preprod');
    }

    public function test_une_cle_de_test_en_production_est_refusee(): void
    {
        // Le site annoncerait des commandes payees sans qu'aucun euro n'arrive.
        $this->expectException(RuntimeException::class);

        StripeCheckoutGateway::assertKeyMatchesEnvironment('sk_test_abc', 'prod');
    }

    public function test_une_cle_absente_laisse_demarrer_partout(): void
    {
        // Une cle vide signifie « paiement non configuré », pas « cle
        // egaree » : le site demarre, et c'est la tentative de paiement qui
        // echouera. Bloquer tout le site — portfolio compris — parce que
        // Stripe n'est pas cablé serait disproportionne.
        StripeCheckoutGateway::assertKeyMatchesEnvironment('', 'prod');
        StripeCheckoutGateway::assertKeyMatchesEnvironment('', 'preprod');

        $this->addToAssertionCount(2);
    }
}
