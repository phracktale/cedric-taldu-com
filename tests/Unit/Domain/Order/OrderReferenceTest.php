<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Exception\InvalidOrderReference;
use App\Domain\Order\OrderReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reference de commande (01-modele §5 : « CT-2026-0001 »).
 *
 * Elle est montree au client, reprise dans les e-mails et sert de
 * client_reference_id cote Stripe : sa forme est un contrat, pas un detail.
 */
#[CoversClass(OrderReference::class)]
final class OrderReferenceTest extends TestCase
{
    public function test_la_premiere_commande_d_une_annee_porte_le_numero_un(): void
    {
        $this->assertSame('CT-2026-0001', OrderReference::following(null, 2026)->value);
    }

    public function test_la_commande_suivante_incremente_le_compteur(): void
    {
        $precedente = OrderReference::fromString('CT-2026-0041');

        $this->assertSame('CT-2026-0042', OrderReference::following($precedente, 2026)->value);
    }

    public function test_le_compteur_repart_a_un_au_changement_d_annee(): void
    {
        // Sans cette remise a zero, la reference cesserait d'identifier
        // l'exercice comptable auquel la commande appartient.
        $precedente = OrderReference::fromString('CT-2026-0387');

        $this->assertSame('CT-2027-0001', OrderReference::following($precedente, 2027)->value);
    }

    public function test_le_compteur_depasse_proprement_quatre_chiffres(): void
    {
        // Le rembourrage est un plancher, pas un plafond : la 10 000e commande
        // ne doit ni tronquer ni repartir a zero.
        $precedente = OrderReference::fromString('CT-2026-9999');

        $this->assertSame('CT-2026-10000', OrderReference::following($precedente, 2026)->value);
    }

    public function test_une_reference_expose_son_annee_et_son_rang(): void
    {
        $reference = OrderReference::fromString('CT-2026-0042');

        $this->assertSame(2026, $reference->year);
        $this->assertSame(42, $reference->sequence);
    }

    #[DataProvider('referencesInvalides')]
    public function test_une_reference_mal_formee_est_refusee(string $brute): void
    {
        // La reference vient de la base, mais aussi d'une URL de consultation
        // de commande : une valeur qui ne respecte pas la forme ne doit jamais
        // atteindre une requete.
        $this->expectException(InvalidOrderReference::class);

        OrderReference::fromString($brute);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referencesInvalides(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'préfixe inconnu' => ['XX-2026-0001'];
        yield 'année trop courte' => ['CT-26-0001'];
        yield 'séquence non numérique' => ['CT-2026-000A'];
        yield 'séquence nulle' => ['CT-2026-0000'];
        yield 'séparateur absent' => ['CT20260001'];
        yield 'minuscules' => ['ct-2026-0001'];
        yield 'espace intercalé' => ['CT-2026- 0001'];
        yield 'injection SQL' => ["CT-2026-0001' OR '1'='1"];
        yield 'octet nul' => ["CT-2026-0001\0"];
        yield 'saut de ligne' => ["CT-2026-0001\n"];
    }

    public function test_la_reference_se_rend_telle_quelle_en_chaine(): void
    {
        $this->assertSame('CT-2026-0042', (string) OrderReference::fromString('CT-2026-0042'));
    }
}
