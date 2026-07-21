<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScanner;

/**
 * src/CLAUDE.md : « Aucune lecture directe de $_GET, $_POST, $_FILES, $_SERVER,
 * $_COOKIE en dehors de Core\Request. »
 *
 * L'interet n'est pas theorique : c'est ce qui garantit que toute entree passe
 * par la normalisation et les controles de Request — decodage du chemin, refus
 * de la traversee, en-tetes transferees ignorees hors proxy de confiance — et
 * que chaque comportement reste testable sans serveur.
 *
 * Toutes les verifications portent sur le code prive de ses commentaires : un
 * commentaire qui explique pourquoi mt_rand est interdit n'est pas un usage de
 * mt_rand.
 */
final class SuperglobalTest extends TestCase
{
    private const FORBIDDEN = ['$_GET', '$_POST', '$_FILES', '$_SERVER', '$_COOKIE', '$_REQUEST', '$GLOBALS'];

    /** Core\Request est le point de contact assume ; PhpSession porte $_SESSION. */
    private const ALLOWED = [
        'src/Core/Request.php' => self::FORBIDDEN,
        'src/Core/PhpSession.php' => ['$_SESSION'],
    ];

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $sources = SourceScanner::files('src');

        $this->assertNotSame([], $sources, 'Aucune source trouvée : le test ne prouverait rien.');

        return array_map(SourceScanner::withoutComments(...), $sources);
    }

    public function test_aucune_superglobale_hors_de_core_request(): void
    {
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            $autorisees = self::ALLOWED[$chemin] ?? [];

            foreach (self::FORBIDDEN as $superglobale) {
                if (in_array($superglobale, $autorisees, true)) {
                    continue;
                }

                if (str_contains($source, $superglobale)) {
                    $fautes[] = sprintf('%s — %s', $chemin, $superglobale);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Superglobale lue en dehors de Core\Request (src/CLAUDE.md).',
            ...$fautes,
        ]));
    }

    public function test_la_session_n_est_manipulee_que_par_php_session(): void
    {
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            if ($chemin === 'src/Core/PhpSession.php') {
                continue;
            }

            if (
                str_contains($source, '$_SESSION')
                || preg_match('/\bsession_(start|regenerate_id|destroy|id)\s*\(/', $source) === 1
            ) {
                $fautes[] = $chemin;
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Manipulation de session hors de Core\PhpSession.',
            ...$fautes,
        ]));
    }

    public function test_aucune_source_d_alea_faible(): void
    {
        // Les jetons CSRF, les jetons d'acces aux commandes et les noms de
        // fichiers televerses viennent tous de random_bytes (Core\SecureRandom).
        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            if (preg_match('/(?<![\w_])(mt_rand|rand|uniqid|str_shuffle|array_rand)\s*\(/', $source) === 1) {
                $fautes[] = $chemin;
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, [
            'Source d’aléa non cryptographique dans src/.',
            ...$fautes,
        ]));
    }

    public function test_aucun_secret_en_dur_dans_le_code(): void
    {
        // CLAUDE.md §6 : rien de secret dans le depot. Les motifs couvrent les
        // formes de cles que ce projet manipulera reellement.
        $motifs = [
            'clé Stripe' => '/\b(sk|pk|whsec|rk)_(test|live)_[A-Za-z0-9]{10,}/',
            'mot de passe assigné' => '/(?i)\$(password|secret|pepper)\s*=\s*[\'"][^\'"$]{8,}[\'"]/',
        ];

        $fautes = [];

        foreach ($this->sources() as $chemin => $source) {
            foreach ($motifs as $nom => $motif) {
                if (preg_match($motif, $source) === 1) {
                    $fautes[] = sprintf('%s — %s', $chemin, $nom);
                }
            }
        }

        $this->assertSame([], $fautes, implode(PHP_EOL, ['Secret en dur dans src/.', ...$fautes]));
    }

    public function test_le_scanner_detecte_reellement_un_usage(): void
    {
        // Un test-scanner qui passe ne prouve rien tant qu'on ne l'a pas vu
        // refuser du code fautif : on lui soumet ici un cas connu.
        $fautif = SourceScanner::withoutComments(
            '<?php /* $_GET est interdit */ $valeur = $_GET["x"]; $jeton = uniqid();'
        );

        $this->assertStringContainsString('$_GET', $fautif);
        $this->assertSame(1, preg_match('/(?<![\w_])(mt_rand|rand|uniqid|str_shuffle|array_rand)\s*\(/', $fautif));
        $this->assertStringNotContainsString('est interdit', $fautif);
    }
}
