<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 06-securite §2 : « Analyse statique des templates : tout <?= doit appeler un
 * helper autorise. »
 *
 * Ce test tourne sur le code source et non sur des reponses HTTP : il couvre
 * donc aussi les gabarits qu'aucun test fonctionnel n'atteint encore, et il
 * couvrira automatiquement ceux des lots suivants.
 */
final class EscapingTest extends TestCase
{
    /**
     * Les seules expressions autorisees derriere « <?= ».
     *
     * Trois d'entre elles sont les helpers d'echappement. Les deux autres sont
     * poses par Core\View lui-meme et ne viennent d'aucune donnee de gabarit :
     *
     *  - $content : rendu de la page inseree dans sa mise en page ;
     *  - $partial(...) : rendu d'un partiel.
     *
     * Dans les deux cas la valeur est le rendu d'un AUTRE gabarit, dont ce
     * meme test controle la source. L'echappement n'est donc pas contourne, il
     * a deja eu lieu — et le re-echapper afficherait le HTML au lieu de le
     * rendre.
     *
     * money() ne calcule rien et ne produit que des chiffres, un separateur et
     * un symbole monetaire : aucune donnee d'utilisateur n'y transite.
     */
    private const ALLOWED = [
        '/^e\(/',
        '/^attr\(/',
        '/^jsonAttr\(/',
        '/^money\(/',
        '/^\$content$/',
        '/^\$partial\(/',
    ];

    public function test_chaque_sortie_de_gabarit_passe_par_un_helper_autorise(): void
    {
        $fautes = [];

        foreach ($this->templates() as $chemin => $source) {
            foreach ($this->shortEchoes($source) as $ligne => $expression) {
                if (!$this->isAllowed($expression)) {
                    $fautes[] = sprintf('%s:%d — <?= %s', $chemin, $ligne, $expression);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Sortie non échappée dans un gabarit.',
            'Toute valeur écrite dans un gabarit passe par e(), attr() ou jsonAttr().',
            ...$fautes,
        ]));
    }

    public function test_aucun_gabarit_n_appelle_echo_ou_print_directement(): void
    {
        // Contourner « <?= » par « <?php echo » contournerait aussi ce test :
        // on ferme la porte explicitement.
        $fautes = [];

        foreach ($this->templates() as $chemin => $source) {
            if (preg_match('/\b(echo|print)\s/', $source) === 1) {
                $fautes[] = $chemin;
            }
        }

        $this->assertSame([], $fautes, 'Un gabarit utilise echo ou print au lieu de « <?= helper() ».');
    }

    public function test_aucun_gabarit_ne_contient_de_gestionnaire_d_evenement_inline(): void
    {
        // La CSP est stricte et sans unsafe-inline : un onclick de maquette
        // laisse dans un gabarit produit une page silencieusement cassee.
        $fautes = [];

        foreach ($this->templates() as $chemin => $source) {
            if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $source) === 1) {
                $fautes[] = $chemin;
            }
        }

        $this->assertSame([], $fautes, 'Gestionnaire d’événement inline : interdit par la CSP (06-securite §2).');
    }

    public function test_aucun_gabarit_n_ecrit_d_url_interne_en_dur(): void
    {
        // CLAUDE.md §4 : toute URL passe par UrlGenerator. Une URL en dur casse
        // la preprod sans casser la prod, donc passe les tests locaux.
        $fautes = [];

        foreach ($this->templates() as $chemin => $source) {
            if (preg_match_all('/(?:href|src|action)\s*=\s*"\/[^"<]*"/', $source, $trouves) >= 1) {
                foreach ($trouves[0] as $trouve) {
                    $fautes[] = $chemin . ' — ' . $trouve;
                }
            }
        }

        $this->assertSame([], $fautes, 'URL interne écrite en dur dans un gabarit.');
    }

    // ------------------------------------------------------------------ outils

    /**
     * @return array<string, string> chemin relatif => source
     */
    private function templates(): array
    {
        $racine = dirname(__DIR__, 2) . '/templates';
        $sources = [];

        $iterateur = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $fichier */
        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen(dirname(__DIR__, 2)) + 1));
            $sources[$chemin] = (string) file_get_contents($fichier->getPathname());
        }

        $this->assertNotSame([], $sources, 'Aucun gabarit trouvé : le test ne prouverait rien.');

        return $sources;
    }

    /**
     * @return array<int, string> numero de ligne => expression
     */
    private function shortEchoes(string $source): array
    {
        if (preg_match_all('/<\?=(.*?)\?>/s', $source, $trouves, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $expressions = [];

        foreach ($trouves[1] as $index => [$expression, $offset]) {
            $ligne = substr_count(substr($source, 0, (int) $trouves[0][$index][1]), "\n") + 1;
            $expressions[$ligne] = trim(preg_replace('/\s+/', ' ', (string) $expression) ?? '');
        }

        return $expressions;
    }

    private function isAllowed(string $expression): bool
    {
        foreach (self::ALLOWED as $motif) {
            if (preg_match($motif, $expression) === 1) {
                return true;
            }
        }

        return false;
    }
}
