<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;

/**
 * 06-securite §7 : un controle AU DEMARRAGE interdit une cle de test en
 * production et une cle de production hors production.
 *
 * « Au demarrage » n'est pas « au premier paiement » : une cle mal placee doit
 * arreter le site avant qu'une seule requete publique ne soit servie, sans
 * quoi la preprod encaisserait pour de vrai, ou la prod annoncerait des
 * paiements fictifs.
 */
final class DemarrageStripeTest extends FunctionalTestCase
{
    public function test_une_cle_de_production_en_preprod_empeche_le_demarrage(): void
    {
        $this->withEnv(['STRIPE_SECRET_KEY' => 'sk_live_dangereuse', 'APP_ENV' => 'preprod']);

        // Une simple page d'accueil suffit : le controle se fait a la
        // construction du conteneur, avant tout traitement.
        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(500, $reponse->status);
        // La page 500 ne fuit ni la cle, ni le motif exact (06-securite §10).
        $this->assertStringNotContainsString('sk_live', $reponse->body);
    }

    public function test_une_cle_de_test_en_preprod_laisse_demarrer(): void
    {
        $this->withEnv(['STRIPE_SECRET_KEY' => 'sk_test_ok', 'APP_ENV' => 'preprod']);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
    }

    public function test_une_cle_absente_laisse_demarrer_hors_production(): void
    {
        // En local et en preprod, une cle vide ne bloque pas : elle vaut « pas
        // encore de paiement configuré », pas « cle de production egaree ».
        $this->withEnv(['STRIPE_SECRET_KEY' => '', 'APP_ENV' => 'preprod']);

        $reponse = $this->requete('GET', '/cedric-taldu/fr/');

        $this->assertSame(200, $reponse->status);
    }
}
