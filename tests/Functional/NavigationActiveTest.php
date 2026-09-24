<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\FunctionalTestCase;

/**
 * Mise en valeur de l'entrée de menu active (revue du 2026-09-24).
 *
 * L'entrée de la rubrique courante porte `aria-current` : c'est à la fois
 * l'information donnée aux lecteurs d'écran et le crochet du style visuel.
 * Le style lui-même (gras, souligné, inverse vidéo, couleur) est un réglage.
 */
final class NavigationActiveTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rubriquesFixes(): iterable
    {
        yield 'à propos' => ['/cedric-taldu/fr/a-propos', '/cedric-taldu/fr/a-propos'];
        yield 'livret' => ['/cedric-taldu/fr/livret', '/cedric-taldu/fr/livret'];
        yield 'contact' => ['/cedric-taldu/fr/contact', '/cedric-taldu/fr/contact'];
    }

    #[DataProvider('rubriquesFixes')]
    public function test_l_entree_de_la_page_courante_porte_aria_current(string $uri, string $lien): void
    {
        $nav = $this->nav($this->get($uri)->body);

        $this->assertMatchesRegularExpression(
            '~<a\s+href="' . preg_quote($lien, '~') . '"\s+aria-current="page"~',
            $nav,
        );
        $this->assertSame(1, substr_count($nav, 'aria-current="page"'));
    }

    public function test_la_galerie_est_active_sur_une_page_de_rubrique(): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $nav = $this->nav($this->get('/cedric-taldu/fr/galerie/encres')->body);

        $this->assertMatchesRegularExpression('~<button[^>]*class="nav-bouton"[^>]*aria-current="true"~', $nav);
    }

    public function test_aucune_entree_n_est_active_sur_l_accueil(): void
    {
        $nav = $this->nav($this->get('/cedric-taldu/fr/')->body);

        $this->assertStringNotContainsString('aria-current', $nav);
    }

    public function test_le_style_actif_par_defaut_est_le_soulignement(): void
    {
        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('data-actif="souligne"', $corps);
    }

    public function test_le_style_actif_suit_le_reglage(): void
    {
        $this->reglage('nav.active_style', '{"style":"inverse"}');

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('data-actif="inverse"', $corps);
    }

    public function test_un_style_actif_inconnu_retombe_sur_le_defaut(): void
    {
        $this->reglage('nav.active_style', '{"style":"\"><script>"}');

        $corps = $this->get('/cedric-taldu/fr/a-propos')->body;

        $this->assertStringContainsString('data-actif="souligne"', $corps);
        $this->assertStringNotContainsString('"><script>', $corps);
    }

    private function nav(string $html): string
    {
        $debut = strpos($html, '<nav aria-label');
        $this->assertNotFalse($debut);
        $fin = strpos($html, '</nav>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }

    private function reglage(string $cle, string $json): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['k' => $cle, 'v' => $json]);
    }
}
