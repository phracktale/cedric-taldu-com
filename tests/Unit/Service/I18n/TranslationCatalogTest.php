<?php

declare(strict_types=1);

namespace Tests\Unit\Service\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Parité des catalogues de traduction (05-i18n-seo §4).
 *
 * « Un test vérifie que fr.php et en.php ont exactement les mêmes clés. » Une
 * clé oubliée dans une langue ne doit pas se découvrir en production sur une
 * page à moitié traduite : elle casse ce test d'abord.
 */
final class TranslationCatalogTest extends TestCase
{
    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 4) . '/resources/lang/' . $locale . '.php';

        return $catalogue;
    }

    public function test_les_deux_catalogues_portent_exactement_les_memes_cles(): void
    {
        $fr = array_keys($this->catalogue('fr'));
        $en = array_keys($this->catalogue('en'));

        sort($fr);
        sort($en);

        $this->assertSame(
            $fr,
            $en,
            'fr.php et en.php doivent porter exactement les mêmes clés : '
            . implode(', ', array_merge(array_diff($fr, $en), array_diff($en, $fr))),
        );
    }

    public function test_aucune_valeur_n_est_vide(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach ($this->catalogue($locale) as $cle => $valeur) {
                $this->assertNotSame('', trim($valeur), sprintf('Valeur vide pour %s (%s).', $cle, $locale));
            }
        }
    }

    public function test_les_catalogues_sont_des_tableaux_plats_de_chaines(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach ($this->catalogue($locale) as $cle => $valeur) {
                $this->assertIsString($cle);
                $this->assertIsString($valeur, sprintf('La clé %s (%s) doit être une chaîne.', $cle, $locale));
            }
        }
    }
}
