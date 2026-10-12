<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\TitleOrder;
use PHPUnit\Framework\TestCase;

/**
 * Ordre alphabétique des titres d'œuvres (demande du 2026-10-12), tel qu'un
 * lecteur l'attend : ni les accents ni la casse ne comptent, et les numéros se
 * suivent — « Repli 2 » avant « Repli 10 ».
 */
final class TitleOrderTest extends TestCase
{
    /**
     * @param  list<string> $titres
     * @return list<string>
     */
    private static function trier(array $titres): array
    {
        usort($titres, TitleOrder::compare(...));

        return $titres;
    }

    public function test_ni_les_accents_ni_la_casse_ne_comptent(): void
    {
        $this->assertSame(
            ['articulation', 'Écorché', 'élan', 'Zénith'],
            self::trier(['Zénith', 'élan', 'articulation', 'Écorché']),
        );
    }

    public function test_les_numeros_se_suivent(): void
    {
        // L'ordre du dictionnaire donnerait 1, 10, 2.
        $this->assertSame(
            ['Repli 1', 'Repli 2', 'Repli 10'],
            self::trier(['Repli 10', 'Repli 2', 'Repli 1']),
        );
    }

    public function test_les_ligatures_se_rangent_a_leurs_lettres(): void
    {
        $this->assertSame(['Nu', 'Œil', 'Pilier'], self::trier(['Pilier', 'Œil', 'Nu']));
    }

    public function test_deux_titres_qui_ne_different_que_par_un_accent_ont_un_ordre_stable(): void
    {
        // Égaux à la comparaison tolérante : le texte exact les départage, pour
        // que l'ordre ne dépende pas de celui de la base.
        $this->assertSame(self::trier(['Élan', 'Elan']), self::trier(['Elan', 'Élan']));
        $this->assertSame(0, TitleOrder::compare('Élan', 'Élan'));
    }
}
