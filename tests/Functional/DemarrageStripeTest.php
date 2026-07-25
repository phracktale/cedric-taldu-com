<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §7 : un contrôle AU DEMARRAGE valide la configuration Stripe.
 *
 * STRIPE_ENV choisit la paire de clés active ; les clés de PRODUCTION ne
 * s'activent qu'en production. Une configuration fautive arrête le site avant
 * qu'une seule requête publique ne soit servie, sans quoi la préprod
 * encaisserait pour de vrai. « Au démarrage » n'est pas « au premier paiement ».
 */
final class DemarrageStripeTest extends FunctionalTestCase
{
    public function test_activer_la_production_hors_prod_empeche_le_demarrage(): void
    {
        $this->withEnv([
            'STRIPE_ENV' => 'prod',
            'STRIPE_LIVE_SECRET_KEY' => 'sk_live_dangereuse',
            'APP_ENV' => 'preprod',
        ]);

        // Une simple page d'accueil suffit : le contrôle se fait à la
        // construction du conteneur, avant tout traitement.
        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(500, $reponse->status);
        // La page 500 ne fuit ni la clé, ni le motif exact (06-securite §10).
        $this->assertStringNotContainsString('sk_live', $reponse->body);
    }

    public function test_une_cle_incoherente_avec_le_mode_empeche_le_demarrage(): void
    {
        // Une sk_live_ rangée dans la paire de test : erreur de saisie arrêtée net.
        $this->withEnv([
            'STRIPE_ENV' => 'test',
            'STRIPE_TEST_SECRET_KEY' => 'sk_live_egaree',
            'APP_ENV' => 'preprod',
        ]);

        $this->assertSame(500, $this->requete('GET', '/cedric-taldu/fr/')->status);
    }

    public function test_le_mode_test_en_preprod_laisse_demarrer(): void
    {
        $this->withEnv([
            'STRIPE_ENV' => 'test',
            'STRIPE_TEST_SECRET_KEY' => 'sk_test_ok',
            'APP_ENV' => 'preprod',
        ]);

        $this->assertSame(200, $this->requete('GET', '/cedric-taldu/fr/')->status);
    }

    public function test_une_configuration_absente_laisse_demarrer(): void
    {
        // Aucune clé et STRIPE_ENV par défaut : « paiement non configuré », le
        // site démarre partout, et c'est la tentative de paiement qui échouera.
        $this->withEnv(['APP_ENV' => 'preprod']);

        $this->assertSame(200, $this->requete('GET', '/cedric-taldu/fr/')->status);
    }
}
