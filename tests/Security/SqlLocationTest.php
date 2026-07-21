<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScanner;

/**
 * 06-securite §1 et src/CLAUDE.md : le SQL n'existe que dans src/Repository/,
 * et aucune valeur variable n'y est jamais interpolee.
 *
 * Ce test lit le code source. Il n'attend pas qu'une injection soit exploitable
 * pour signaler qu'elle est possible.
 */
final class SqlLocationTest extends TestCase
{
    /**
     * Fichiers autorises a contenir du SQL hors de src/Repository/.
     *
     * Core/Migrator applique les fichiers de migrations/ et tient la table qui
     * les suit : il ne peut pas passer par un depot, puisqu'il construit le
     * schema dont les depots dependent. Il n'interpole aucune variable.
     */
    private const ALLOWED_OUTSIDE_REPOSITORY = [
        'src/Core/Migrator.php',
    ];

    private const SQL_KEYWORDS = '/\b(SELECT\s|INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM'
        . '|CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE\s)/i';

    public function test_aucun_sql_hors_des_depots(): void
    {
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            if (str_starts_with($chemin, 'src/Repository/')) {
                continue;
            }

            if (in_array($chemin, self::ALLOWED_OUTSIDE_REPOSITORY, true)) {
                continue;
            }

            foreach ($this->stringLiterals($source) as $ligne => $litteral) {
                if (preg_match(self::SQL_KEYWORDS, $litteral) === 1) {
                    $fautes[] = sprintf('%s:%d', $chemin, $ligne);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'SQL trouvé en dehors de src/Repository/ (src/CLAUDE.md).',
            ...$fautes,
        ]));
    }

    public function test_aucune_variable_interpolee_dans_une_chaine_sql(): void
    {
        // « SELECT * FROM artworks ORDER BY {$sort} » est la forme exacte que
        // src/CLAUDE.md donne en exemple d'interdit. Les identifiants
        // dynamiques passent par une liste blanche en dur.
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            foreach ($this->stringLiterals($source) as $ligne => $litteral) {
                if (preg_match(self::SQL_KEYWORDS, $litteral) !== 1) {
                    continue;
                }

                if (preg_match('/\$\{?[a-zA-Z_]/', $litteral) === 1) {
                    $fautes[] = sprintf('%s:%d — %s', $chemin, $ligne, trim($litteral));
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Variable interpolée dans une chaîne SQL : toute valeur est un paramètre lié.',
            ...$fautes,
        ]));
    }

    public function test_aucune_concatenation_dans_une_chaine_sql(): void
    {
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            // Une chaine SQL suivie d'un « . » et d'une variable sur la meme
            // ligne : concatenation d'une valeur dans la requete.
            $motif = '/["\'][^"\']*(?:SELECT |INSERT INTO|UPDATE |DELETE FROM)'
                . '[^"\']*["\']\s*\.\s*\$/i';

            if (preg_match_all($motif, $source, $trouves) >= 1) {
                foreach ($trouves[0] as $trouve) {
                    $fautes[] = $chemin . ' — ' . trim($trouve);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Concaténation d’une variable dans une requête SQL.',
            ...$fautes,
        ]));
    }

    public function test_les_fonctions_dangereuses_sont_absentes_du_code(): void
    {
        // src/CLAUDE.md, « Ce que l'on ne fait pas ».
        $interdits = [
            'eval' => '/\beval\s*\(/',
            'extract' => '/\bextract\s*\(/',
            'create_function' => '/\bcreate_function\s*\(/',
            'unserialize' => '/\bunserialize\s*\(/',
            'mail' => '/(?<![\w>$])mail\s*\(/',
            'suppression d’erreur @' => '/(?<![\w"\'*])@[a-z_]+\s*\(/i',
        ];

        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            $sansCommentaires = $this->stripComments($source);

            foreach ($interdits as $nom => $motif) {
                if (preg_match($motif, $sansCommentaires) === 1) {
                    $fautes[] = sprintf('%s — %s', $chemin, $nom);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, ['Fonction interdite employée.', ...$fautes]));
    }

    // ------------------------------------------------------------------ outils

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $sources = SourceScanner::files('src');

        $this->assertNotSame([], $sources, 'Aucune source trouvée : le test ne prouverait rien.');

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private function stringLiterals(string $source): array
    {
        return SourceScanner::stringLiterals($source);
    }

    private function stripComments(string $source): string
    {
        return SourceScanner::withoutComments($source);
    }
}
