<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\ValidationFailed;
use App\Core\Rule;
use App\Core\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(Rule::class)]
final class ValidatorTest extends TestCase
{
    public function test_un_schema_valide_rend_les_valeurs_nettoyees(): void
    {
        $valide = (new Validator())->validate(
            ['nom' => '  Cédric  ', 'email' => 'contact@cedrictaldu.com'],
            ['nom' => Rule::text(max: 160), 'email' => Rule::email()],
        );

        $this->assertSame(['nom' => 'Cédric', 'email' => 'contact@cedrictaldu.com'], $valide);
    }

    public function test_un_champ_non_declare_est_ignore(): void
    {
        // src/CLAUDE.md : pas d'affectation en masse. On nomme les champs
        // attendus, un par un ; tout le reste est jete, jamais propage.
        $valide = (new Validator())->validate(
            ['nom' => 'Cédric', 'role' => 'admin', 'price_cents' => '1'],
            ['nom' => Rule::text(max: 160)],
        );

        $this->assertSame(['nom' => 'Cédric'], $valide);
    }

    public function test_un_champ_requis_manquant_est_signale(): void
    {
        try {
            (new Validator())->validate([], ['nom' => Rule::text(max: 160)]);
            $this->fail('Une validation en echec etait attendue.');
        } catch (ValidationFailed $exception) {
            $this->assertArrayHasKey('nom', $exception->errors());
        }
    }

    public function test_un_champ_facultatif_absent_ne_bloque_pas(): void
    {
        $valide = (new Validator())->validate([], ['telephone' => Rule::text(max: 40, required: false)]);

        $this->assertSame([], $valide);
    }

    public function test_toutes_les_erreurs_sont_rendues_ensemble(): void
    {
        // Un formulaire qui ne signale qu'une erreur a la fois fait recommencer
        // le visiteur autant de fois qu'il y a de champs fautifs.
        try {
            (new Validator())->validate(
                ['nom' => '', 'email' => 'pas-une-adresse'],
                ['nom' => Rule::text(max: 160), 'email' => Rule::email()],
            );
            $this->fail('Une validation en echec etait attendue.');
        } catch (ValidationFailed $exception) {
            $this->assertSame(['nom', 'email'], array_keys($exception->errors()));
        }
    }

    // -------------------------------------------------------------- longueur

    public function test_une_valeur_trop_longue_est_refusee(): void
    {
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['nom' => str_repeat('a', 161)], ['nom' => Rule::text(max: 160)]);
    }

    public function test_la_longueur_se_mesure_en_caracteres_et_non_en_octets(): void
    {
        // « é » compte pour deux octets : mesurer en octets amputerait un texte
        // francais d'un tiers de sa longueur autorisee.
        $valide = (new Validator())->validate(
            ['nom' => str_repeat('é', 10)],
            ['nom' => Rule::text(max: 10)],
        );

        $this->assertSame(str_repeat('é', 10), $valide['nom']);
    }

    // ---------------------------------------------------- caractères interdits

    #[DataProvider('valeursAvecControle')]
    public function test_un_caractere_de_controle_dans_un_champ_texte_est_refuse(string $valeur): void
    {
        // 06-securite §6.6 : toute valeur entrant dans un e-mail est purgee de
        // \r et \n. On refuse en amont plutot que de nettoyer en silence.
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['sujet' => $valeur], ['sujet' => Rule::text(max: 220)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valeursAvecControle(): iterable
    {
        yield 'LF' => ["Bonjour\nBcc: victime@exemple.test"];
        yield 'CR' => ["Bonjour\rBcc: victime@exemple.test"];
        yield 'octet nul' => ["Bonjour\0"];
    }

    public function test_un_champ_multiligne_accepte_les_sauts_de_ligne(): void
    {
        $valide = (new Validator())->validate(
            ['message' => "Bonjour,\n\nJe suis intéressé."],
            ['message' => Rule::multiline(max: 4000)],
        );

        $this->assertSame("Bonjour,\n\nJe suis intéressé.", $valide['message']);
    }

    public function test_un_champ_multiligne_refuse_quand_meme_l_octet_nul(): void
    {
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['message' => "Bonjour\0"], ['message' => Rule::multiline(max: 4000)]);
    }

    // ------------------------------------------------------------------ email

    #[DataProvider('adressesInvalides')]
    public function test_une_adresse_invalide_est_refusee(string $adresse): void
    {
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['email' => $adresse], ['email' => Rule::email()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adressesInvalides(): iterable
    {
        yield 'sans arobase' => ['contact.cedrictaldu.com'];
        yield 'sans domaine' => ['contact@'];
        yield 'avec espace' => ['con tact@cedrictaldu.com'];
        yield 'avec saut de ligne' => ["contact@cedrictaldu.com\nBcc: victime@exemple.test"];
        yield 'avec retour chariot' => ["contact@cedrictaldu.com\r"];
    }

    // ------------------------------------------------------------ slug et id

    public function test_un_slug_valide_est_accepte(): void
    {
        $valide = (new Validator())->validate(
            ['slug' => 'autoportrait-au-baron-samedi'],
            ['slug' => Rule::slug()],
        );

        $this->assertSame('autoportrait-au-baron-samedi', $valide['slug']);
    }

    #[DataProvider('slugsInvalides')]
    public function test_un_slug_malforme_est_refuse(string $slug): void
    {
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['slug' => $slug], ['slug' => Rule::slug()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function slugsInvalides(): iterable
    {
        yield 'majuscules' => ['Articulation'];
        yield 'accent' => ['œuvre'];
        yield 'tiret double' => ['a--b'];
        yield 'tiret initial' => ['-a'];
        yield 'slash' => ['a/b'];
        yield 'charge XSS' => ['<script>'];
    }

    public function test_un_identifiant_est_un_entier_strictement_positif(): void
    {
        $valide = (new Validator())->validate(['id' => '42'], ['id' => Rule::id()]);

        $this->assertSame(42, $valide['id']);
    }

    #[DataProvider('identifiantsInvalides')]
    public function test_un_identifiant_malforme_est_refuse(string $valeur): void
    {
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(['id' => $valeur], ['id' => Rule::id()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiantsInvalides(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'négatif' => ['-1'];
        yield 'décimal' => ['1.5'];
        yield 'zéro initial' => ['007'];
        yield 'injection SQL' => ['1 OR 1=1'];
        yield 'vide' => [''];
    }

    // ------------------------------------------------------------ liste close

    public function test_une_valeur_de_liste_close_est_acceptee(): void
    {
        $valide = (new Validator())->validate(
            ['langue' => 'en'],
            ['langue' => Rule::among(['fr', 'en'])],
        );

        $this->assertSame('en', $valide['langue']);
    }

    public function test_une_valeur_hors_liste_close_est_refusee(): void
    {
        // Sert notamment aux colonnes de tri : une liste blanche en dur est la
        // seule facon d'accepter un identifiant SQL dynamique (06-securite §1).
        $this->expectException(ValidationFailed::class);

        (new Validator())->validate(
            ['tri' => 'position; DROP TABLE artworks'],
            ['tri' => Rule::among(['position', 'recent'])],
        );
    }

    // ------------------------------------------------------------- messages

    public function test_le_message_d_erreur_ne_renvoie_jamais_la_valeur_recue(): void
    {
        // Un message reprenant l'entree telle quelle est un XSS reflechi des que
        // le formulaire la reaffiche.
        try {
            (new Validator())->validate(['slug' => '<img onerror=alert(1)>'], ['slug' => Rule::slug()]);
            $this->fail('Une validation en echec etait attendue.');
        } catch (ValidationFailed $exception) {
            $this->assertStringNotContainsString('onerror', implode(' ', $exception->errors()));
        }
    }
}
