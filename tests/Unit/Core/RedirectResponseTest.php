<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\UnsafeRedirect;
use App\Core\RedirectResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectResponse::class)]
final class RedirectResponseTest extends TestCase
{
    public function test_une_redirection_est_temporaire_par_defaut(): void
    {
        // 05-i18n-seo §2 : la redirection de la racine depend de la negociation
        // de langue, elle ne peut pas etre mise en cache definitivement.
        $reponse = RedirectResponse::to('/cedric-taldu/fr/');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('/cedric-taldu/fr/', $reponse->header('Location'));
    }

    public function test_une_redirection_permanente_est_possible(): void
    {
        $reponse = RedirectResponse::to('/cedric-taldu/fr/oeuvre/nouveau-slug', 301);

        $this->assertSame(301, $reponse->status);
    }

    public function test_le_corps_reste_vide(): void
    {
        $this->assertSame('', RedirectResponse::to('/fr/')->body);
    }

    public function test_un_statut_qui_n_est_pas_une_redirection_est_refuse(): void
    {
        $this->expectException(UnsafeRedirect::class);

        RedirectResponse::to('/fr/', 200);
    }

    #[DataProvider('destinationsExternes')]
    public function test_une_destination_hors_du_site_est_refusee(string $destination): void
    {
        // src/CLAUDE.md : pas de header('Location: ' . $input). Une redirection
        // ouverte sert a rendre credible un hameconnage depuis notre domaine.
        $this->expectException(UnsafeRedirect::class);

        RedirectResponse::to($destination);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function destinationsExternes(): iterable
    {
        yield 'URL absolue' => ['https://exemple-malveillant.test/piege'];
        yield 'schéma javascript' => ['javascript:alert(1)'];
        yield 'schéma data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'protocole relatif' => ['//exemple-malveillant.test/piege'];
        yield 'protocole relatif encodé' => ['/%2fexemple-malveillant.test/piege'];
        yield 'antislash' => ['/\\exemple-malveillant.test/piege'];
        yield 'chemin relatif' => ['fr/panier'];
        yield 'chaîne vide' => [''];
    }

    #[DataProvider('injectionsDansLaDestination')]
    public function test_un_saut_de_ligne_dans_la_destination_est_refuse(string $destination): void
    {
        $this->expectException(UnsafeRedirect::class);

        RedirectResponse::to($destination);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionsDansLaDestination(): iterable
    {
        yield 'LF' => ["/fr/\nSet-Cookie: ct_session=vole"];
        yield 'CR' => ["/fr/\rSet-Cookie: ct_session=vole"];
        yield 'octet nul' => ["/fr/\0"];
    }
}
