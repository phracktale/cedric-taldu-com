<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Spam;

use App\Domain\Locale;
use App\Service\Spam\SpamHeuristics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Heuristiques alimentant `spam_score` (06-securite §6.4).
 *
 * Aucune de ces heuristiques ne rejette à elle seule : elles CONTRIBUENT à un
 * score, et c'est le SpamGuard qui décide au-delà d'un seuil. Un faux positif
 * isolé (un message légitime tout en majuscules) ne doit pas suffire.
 */
#[CoversClass(SpamHeuristics::class)]
final class SpamHeuristicsTest extends TestCase
{
    public function test_un_message_ordinaire_a_un_score_nul(): void
    {
        $heuristics = new SpamHeuristics();

        $score = $heuristics->score(
            'Bonjour, je suis intéressé par cette œuvre. Est-elle toujours disponible ?',
            Locale::Fr,
        );

        $this->assertSame(0, $score);
    }

    public function test_plus_de_deux_url_pese_dans_le_score(): void
    {
        $heuristics = new SpamHeuristics();

        $score = $heuristics->score(
            'Visitez http://a.example et https://b.example et aussi www.c.example maintenant',
            Locale::Fr,
        );

        $this->assertGreaterThanOrEqual(2, $score);
    }

    public function test_deux_url_ne_declenchent_pas_l_heuristique_url(): void
    {
        $heuristics = new SpamHeuristics();

        // Un lien vers son portfolio et un vers son Instagram : légitime.
        $score = $heuristics->score(
            'Voici mon site https://moi.example et mon profil https://autre.example, à bientôt',
            Locale::Fr,
        );

        $this->assertSame(0, $score);
    }

    public function test_un_message_entierement_en_majuscules_pese(): void
    {
        $heuristics = new SpamHeuristics();

        $score = $heuristics->score(
            'ACHETEZ MAINTENANT CETTE OFFRE EXCEPTIONNELLE RESERVEE POUR VOUS',
            Locale::Fr,
        );

        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_l_absence_totale_d_accents_sur_un_formulaire_fr_pese(): void
    {
        $heuristics = new SpamHeuristics();

        // Un texte français assez long sans le moindre accent est suspect
        // (06-securite §4). Sur un formulaire EN, ce serait normal.
        $texte = 'bonjour je voudrais des informations sur la disponibilite de cette piece unique';

        $this->assertGreaterThanOrEqual(1, $heuristics->score($texte, Locale::Fr));
        $this->assertSame(0, $heuristics->score($texte, Locale::En));
    }

    public function test_un_court_message_sans_accent_ne_pese_pas(): void
    {
        $heuristics = new SpamHeuristics();

        // « Merci beaucoup » : trop court pour conclure quoi que ce soit de
        // l'absence d'accents.
        $this->assertSame(0, $heuristics->score('Merci beaucoup', Locale::Fr));
    }

    public function test_les_signaux_se_cumulent(): void
    {
        $heuristics = new SpamHeuristics();

        $score = $heuristics->score(
            'ACHETEZ ICI HTTP://A.EXAMPLE ET HTTPS://B.EXAMPLE ET WWW.C.EXAMPLE VITE VITE',
            Locale::Fr,
        );

        // Trois URL (+2), tout en majuscules (+1), sans accents sur du FR (+1).
        $this->assertGreaterThanOrEqual(4, $score);
    }
}
