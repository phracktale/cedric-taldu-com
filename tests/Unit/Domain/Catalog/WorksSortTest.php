<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\WorksSort;
use PHPUnit\Framework\TestCase;

/**
 * Classement de la page « Toutes les œuvres » (demande du 2026-10-12) : trois
 * méthodes au choix de l'artiste, réglage `works.sort`.
 */
final class WorksSortTest extends TestCase
{
    public function test_sans_reglage_l_ordre_est_libre(): void
    {
        // L'ordre libre part de l'ordre affiché jusque-là : rien ne bouge tant
        // que l'artiste n'a rien choisi.
        $this->assertSame(WorksSort::Manual, WorksSort::fromStored([]));
    }

    public function test_le_reglage_fait_l_aller_retour(): void
    {
        foreach (WorksSort::cases() as $mode) {
            $this->assertSame($mode, WorksSort::fromStored($mode->toArray()));
        }

        $this->assertSame(['mode' => 'alphabetique'], WorksSort::Alphabetical->toArray());
    }

    public function test_une_valeur_inconnue_retombe_sur_l_ordre_libre(): void
    {
        $this->assertSame(WorksSort::Manual, WorksSort::fromStored(['mode' => 'au-hasard']));
        $this->assertSame(WorksSort::Manual, WorksSort::fromStored(['mode' => ['recentes']]));
    }

    public function test_chaque_methode_a_un_libelle_pour_l_artiste(): void
    {
        $this->assertSame('Ordre libre, par glisser-déposer dans la liste', WorksSort::Manual->label());
        $this->assertSame('Alphabétique', WorksSort::Alphabetical->label());
        $this->assertSame('Les plus récentes d’abord (année de l’œuvre)', WorksSort::Recent->label());
    }
}
