<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\MapSettings;
use PHPUnit\Framework\TestCase;

/**
 * Carte interactive (retours du 2026-09-25, Modules) : point central, zoom et
 * marqueurs titrés, lus défensivement et saisis en back-office.
 */
final class MapSettingsTest extends TestCase
{
    public function test_sans_reglage_la_carte_est_centree_sur_amiens(): void
    {
        $carte = MapSettings::fromStored([]);

        $this->assertEqualsWithDelta(49.894, $carte->latitude, 0.01);
        $this->assertEqualsWithDelta(2.295, $carte->longitude, 0.01);
        $this->assertSame(12, $carte->zoom);
        $this->assertSame([], $carte->markers);
    }

    public function test_la_saisie_donne_centre_zoom_et_marqueurs(): void
    {
        [$carte, $erreurs] = MapSettings::fromForm([
            'lat' => '49,9147', 'lng' => '2.2365', 'zoom' => '14',
            'm0_titre' => 'Atelier', 'm0_description' => 'Sur rendez-vous', 'm0_lat' => '49.91', 'm0_lng' => '2.23',
            'm1_titre' => '', 'm1_description' => '', 'm1_lat' => '', 'm1_lng' => '',
        ]);

        $this->assertSame([], $erreurs);
        $this->assertSame(49.9147, $carte->latitude);
        $this->assertSame(14, $carte->zoom);
        $this->assertSame([['title' => 'Atelier', 'description' => 'Sur rendez-vous', 'lat' => 49.91, 'lng' => 2.23]], $carte->markers);
        $this->assertSame($carte->toArray(), MapSettings::fromStored($carte->toArray())->toArray());
    }

    public function test_les_saisies_hors_bornes_sont_refusees(): void
    {
        [, $erreurs] = MapSettings::fromForm([
            'lat' => '95', 'lng' => 'est', 'zoom' => '40',
            'm0_titre' => '', 'm0_description' => 'Sans titre', 'm0_lat' => '49', 'm0_lng' => '2',
            'm1_titre' => 'Loin', 'm1_description' => '', 'm1_lat' => '49', 'm1_lng' => '200',
        ]);

        $this->assertSame([
            'Point central : latitude « 95 » hors de -90 à 90.',
            'Point central : longitude « est » illisible.',
            'Zoom : un nombre de 1 à 18.',
            'Marqueur 1 : donnez-lui un titre.',
            'Marqueur « Loin » : longitude « 200 » hors de -180 à 180.',
        ], $erreurs);
    }

    public function test_un_reglage_abime_retombe_sur_des_valeurs_sures(): void
    {
        $carte = MapSettings::fromStored([
            'lat' => 'x', 'lng' => 500, 'zoom' => 99,
            'markers' => [['title' => 'Bon', 'lat' => 1, 'lng' => 2], ['title' => '', 'lat' => 1, 'lng' => 2], 'nimporte'],
        ]);

        $this->assertEqualsWithDelta(49.894, $carte->latitude, 0.01);
        $this->assertSame(12, $carte->zoom);
        $this->assertSame([['title' => 'Bon', 'description' => '', 'lat' => 1.0, 'lng' => 2.0]], $carte->markers);
    }
}
