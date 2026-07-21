<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\InvalidResponse;
use App\Core\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function test_une_reponse_repond_200_par_defaut(): void
    {
        $reponse = new Response('Bonjour');

        $this->assertSame(200, $reponse->status);
        $this->assertSame('Bonjour', $reponse->body);
    }

    public function test_une_reponse_html_porte_le_type_de_contenu_et_l_encodage(): void
    {
        $reponse = Response::html('<h1>Bonjour</h1>');

        $this->assertSame('text/html; charset=utf-8', $reponse->header('Content-Type'));
    }

    public function test_les_en_tetes_sont_lues_sans_distinction_de_casse(): void
    {
        $reponse = (new Response())->withHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame('noindex, nofollow', $reponse->header('x-robots-tag'));
        $this->assertSame('noindex, nofollow', $reponse->header('X-ROBOTS-TAG'));
        $this->assertNull($reponse->header('X-Absent'));
    }

    public function test_la_reponse_est_immuable(): void
    {
        $origine = new Response('corps');

        $modifiee = $origine->withHeader('X-Test', 'valeur')->withStatus(404);

        $this->assertNotSame($origine, $modifiee);
        $this->assertNull($origine->header('X-Test'));
        $this->assertSame(200, $origine->status);
        $this->assertSame(404, $modifiee->status);
        $this->assertSame('corps', $modifiee->body);
    }

    public function test_une_en_tete_definie_deux_fois_prend_la_derniere_valeur(): void
    {
        $reponse = (new Response())
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('cache-control', 'private');

        $this->assertSame('private', $reponse->header('Cache-Control'));
        $this->assertCount(1, $reponse->headers);
    }

    #[DataProvider('injectionsDEnTete')]
    public function test_un_retour_chariot_dans_une_en_tete_est_refuse(string $nom, string $valeur): void
    {
        // Un CR ou un LF permettrait d'injecter une en-tete supplementaire, voire
        // un corps de reponse entier (scission de reponse HTTP).
        $this->expectException(InvalidResponse::class);

        (new Response())->withHeader($nom, $valeur);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function injectionsDEnTete(): iterable
    {
        yield 'LF dans la valeur' => ['X-Test', "valeur\nX-Injectee: oui"];
        yield 'CR dans la valeur' => ['X-Test', "valeur\rX-Injectee: oui"];
        yield 'CRLF dans la valeur' => ['X-Test', "valeur\r\nX-Injectee: oui"];
        yield 'LF dans le nom' => ["X-Test\nX-Injectee", 'valeur'];
        yield 'octet nul dans la valeur' => ['X-Test', "valeur\0"];
    }

    public function test_un_statut_hors_plage_http_est_refuse(): void
    {
        $this->expectException(InvalidResponse::class);

        (new Response())->withStatus(99);
    }

    public function test_l_emission_ecrit_le_corps_sur_la_sortie(): void
    {
        $reponse = Response::html('<h1>Bonjour</h1>');

        ob_start();
        $reponse->send();
        $sortie = ob_get_clean();

        $this->assertSame('<h1>Bonjour</h1>', $sortie);
    }

    public function test_une_reponse_204_n_emet_aucun_corps(): void
    {
        $reponse = (new Response('ignoré'))->withStatus(204);

        ob_start();
        $reponse->send();
        $sortie = ob_get_clean();

        $this->assertSame('', $sortie);
    }
}
