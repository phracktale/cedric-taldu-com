<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Env;
use App\Core\Exception\MissingEnvironmentVariable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Support/fixtures/env';

    public function test_une_variable_definie_dans_le_fichier_est_retournee(): void
    {
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->assertSame('preprod', $env->get('APP_ENV'));
    }

    public function test_une_variable_absente_leve_une_exception(): void
    {
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->expectException(MissingEnvironmentVariable::class);

        $env->get('CLE_INEXISTANTE');
    }

    public function test_le_message_de_l_exception_nomme_la_variable_manquante(): void
    {
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->expectExceptionMessage('CLE_INEXISTANTE');

        $env->get('CLE_INEXISTANTE');
    }

    public function test_une_variable_declaree_vide_est_une_valeur_definie(): void
    {
        // TRUSTED_PROXIES est légitimement vide en production : l'absence de proxy
        // n'est pas une erreur de configuration (09-environnements §4).
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->assertSame('', $env->get('TRUSTED_PROXIES'));
    }

    public function test_l_environnement_systeme_prime_sur_le_fichier(): void
    {
        // Docker Compose fixe APP_ENV par `environment:` alors que le code monté
        // contient un .env de développement : le conteneur doit gagner.
        $env = Env::load(self::FIXTURES . '/complet.env', ['APP_ENV' => 'dev']);

        $this->assertSame('dev', $env->get('APP_ENV'));
    }

    public function test_un_fichier_absent_laisse_l_environnement_systeme_seul(): void
    {
        $env = Env::load(self::FIXTURES . '/aucun-fichier-ici.env', ['APP_ENV' => 'prod']);

        $this->assertSame('prod', $env->get('APP_ENV'));
    }

    public function test_getOptional_retourne_le_defaut_sans_lever_d_exception(): void
    {
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->assertSame('valeur-de-repli', $env->getOptional('CLE_INEXISTANTE', 'valeur-de-repli'));
    }

    public function test_has_distingue_une_cle_definie_d_une_cle_absente(): void
    {
        $env = Env::load(self::FIXTURES . '/complet.env');

        $this->assertTrue($env->has('APP_ENV'));
        $this->assertFalse($env->has('CLE_INEXISTANTE'));
    }

    public function test_les_lignes_vides_et_les_commentaires_sont_ignores(): void
    {
        $valeurs = Env::parse("# un commentaire\n\nAPP_ENV=prod\n   # indenté\n");

        $this->assertSame(['APP_ENV' => 'prod'], $valeurs);
    }

    public function test_les_guillemets_entourant_une_valeur_sont_retires(): void
    {
        $valeurs = Env::parse("DOUBLES=\"avec espaces\"\nSIMPLES='autre valeur'\n");

        $this->assertSame(['DOUBLES' => 'avec espaces', 'SIMPLES' => 'autre valeur'], $valeurs);
    }

    public function test_une_valeur_peut_contenir_un_signe_egal(): void
    {
        // Un secret encodé en base64 se termine fréquemment par « = ».
        $valeurs = Env::parse("SECURITY_PEPPER=aGV1cmV1c2VtZW50==\n");

        $this->assertSame(['SECURITY_PEPPER' => 'aGV1cmV1c2VtZW50=='], $valeurs);
    }

    public function test_un_diese_entre_guillemets_n_est_pas_un_debut_de_commentaire(): void
    {
        $valeurs = Env::parse("DB_PASSWORD=\"mot#de#passe\"\n");

        $this->assertSame(['DB_PASSWORD' => 'mot#de#passe'], $valeurs);
    }

    public function test_un_commentaire_en_fin_de_ligne_est_retire_hors_guillemets(): void
    {
        $valeurs = Env::parse("APP_ENV=prod # environnement cible\n");

        $this->assertSame(['APP_ENV' => 'prod'], $valeurs);
    }

    public function test_les_espaces_autour_du_nom_et_de_la_valeur_sont_retires(): void
    {
        $valeurs = Env::parse("  APP_ENV  =  prod  \n");

        $this->assertSame(['APP_ENV' => 'prod'], $valeurs);
    }
}
