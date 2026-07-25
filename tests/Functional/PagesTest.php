<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FunctionalTestCase;

/**
 * Pages éditoriales à code fixe (02-front §6).
 *
 * Les cinq pages sont posées par la migration 0007 : elles existent dans tout
 * environnement, mentions légales et CGV comprises, sans jeu de démonstration.
 */
final class PagesTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pagesFr(): iterable
    {
        yield 'à propos' => ['/cedric-taldu/fr/a-propos', 'À propos'];
        yield 'livret' => ['/cedric-taldu/fr/livret', 'Livret'];
        yield 'mentions légales' => ['/cedric-taldu/fr/mentions-legales', 'Mentions légales'];
        yield 'confidentialité' => ['/cedric-taldu/fr/confidentialite', 'Confidentialité'];
        yield 'CGV' => ['/cedric-taldu/fr/conditions-generales-de-vente', 'Conditions générales de vente'];
    }

    #[DataProvider('pagesFr')]
    public function test_chaque_page_fixe_repond_200_avec_son_titre(string $uri, string $titre): void
    {
        $reponse = $this->get($uri);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('<h1>' . $titre . '</h1>', $reponse->body);
    }

    public function test_la_page_anglaise_repond_aussi(): void
    {
        $reponse = $this->get('/cedric-taldu/en/about');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('About', $reponse->body);
    }

    public function test_une_page_porte_son_canonique_et_ses_hreflang(): void
    {
        $corps = $this->get('/cedric-taldu/fr/mentions-legales')->body;

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://customer.phracktale.com/cedric-taldu/fr/mentions-legales">',
            $corps,
        );
        $this->assertStringContainsString('hreflang="en"', $corps);
        $this->assertStringContainsString('hreflang="x-default"', $corps);
    }

    public function test_une_page_depubliee_repond_404(): void
    {
        // Pas d'énumération : une page dépubliée est introuvable, pas interdite.
        $this->pdo->exec("UPDATE pages SET is_published = 0 WHERE code = 'about'");

        $this->assertSame(404, $this->get('/cedric-taldu/fr/a-propos')->status);
    }
}
