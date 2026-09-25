<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Editorial;

use App\Domain\Editorial\SiteIdentity;
use App\Domain\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Paramètres › Global (retours du 2026-09-25) : l'identité du site — nom de
 * l'artiste, accroche, métier, ville, réseaux — n'est plus écrite en dur. Sans
 * réglage, ce sont les valeurs historiques du site de Cédric Taldu.
 */
final class SiteIdentityTest extends TestCase
{
    public function test_sans_reglage_l_identite_historique(): void
    {
        $site = SiteIdentity::fromStored([]);

        $this->assertSame('Cédric Taldu', $site->name);
        $this->assertSame('artiste plasticien — Amiens', $site->tagline(Locale::Fr));
        $this->assertSame('visual artist — Amiens, France', $site->tagline(Locale::En));
        $this->assertSame('Artiste plasticien, Amiens, Hauts-de-France', $site->role(Locale::Fr));
        $this->assertSame('Amiens', $site->city);
        $this->assertSame(2025, $site->since);
        $this->assertSame('Cédric Taldu | Artiste peintre et dessinateur à Amiens', $site->homeTitle(Locale::Fr));
        $this->assertSame([], $site->socialLinks());
    }

    public function test_la_saisie_complete_l_identite(): void
    {
        [$site, $erreurs] = SiteIdentity::fromForm([
            'nom' => 'Marie Dupont',
            'accroche_fr' => 'peintre — Lille', 'accroche_en' => '',
            'metier_fr' => 'Peintre, Lille', 'metier_en' => 'Painter, Lille',
            'ville' => 'Lille', 'depuis' => '2019',
            'titre_accueil_fr' => '', 'titre_accueil_en' => '',
            'reseau_0' => 'https://www.instagram.com/mariedupont', 'reseau_1' => '', 'reseau_2' => 'https://exemple.art/', 'reseau_3' => '',
        ]);

        $this->assertSame([], $erreurs);
        $this->assertSame('Marie Dupont', $site->name);
        // Anglais vide : repli sur le français.
        $this->assertSame('peintre — Lille', $site->tagline(Locale::En));
        // Titre d'accueil vide : nom et métier.
        $this->assertSame('Marie Dupont | Peintre, Lille', $site->homeTitle(Locale::Fr));
        $this->assertSame([
            ['url' => 'https://www.instagram.com/mariedupont', 'label' => 'Instagram'],
            ['url' => 'https://exemple.art/', 'label' => 'exemple.art'],
        ], $site->socialLinks());
        $this->assertSame($site->toArray(), SiteIdentity::fromStored($site->toArray())->toArray());
    }

    public function test_les_saisies_invalides_sont_signalees(): void
    {
        [, $erreurs] = SiteIdentity::fromForm([
            'nom' => '', 'ville' => 'Lille', 'depuis' => '1850',
            'reseau_0' => 'javascript:alert(1)',
        ]);

        $this->assertSame([
            'Nom : obligatoire.',
            'Première année : entre 1900 et l’année en cours.',
            'Réseau « javascript:alert(1) » : une adresse en https://.',
        ], $erreurs);
    }
}
