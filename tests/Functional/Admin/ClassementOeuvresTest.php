<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\UserFactory;

/**
 * Classement de la page « Toutes les œuvres » (demande du 2026-10-12, suite du
 * compte rendu client : « comment sont-elles classées ? »). L'artiste choisit,
 * sur l'écran des œuvres, entre l'ordre alphabétique, les plus récentes d'abord
 * et un ordre libre réglé par glisser-déposer dans la liste — les flèches
 * restant là pour qui n'a pas JavaScript.
 *
 * La liste d'administration montre l'ordre de la page publique correspondante :
 * sans filtre, celui de « Toutes les œuvres » ; filtrée sur une galerie, celui
 * de la galerie.
 */
final class ClassementOeuvresTest extends AdminTestCase
{
    private const LISTE = '/cedric-taldu/admin/oeuvres';
    private const PAGE = '/cedric-taldu/fr/oeuvres';

    private int $encres;
    private int $huiles;

    /** @var array<string, int> identifiants par titre */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->encres = (new CategoryFactory($this->pdo))->atPosition(1)->translated('fr', 'encres', 'Encres')->create();
        $this->huiles = (new CategoryFactory($this->pdo))->atPosition(2)->translated('fr', 'huiles', 'Huiles')->create();

        // Ordre libre de départ : Zénith, Articulation, Écorché.
        $this->id['Zénith'] = $this->oeuvre('zenith', 'Zénith', 2020, 0, $this->encres);
        $this->id['Articulation'] = $this->oeuvre('articulation', 'Articulation', 2026, 1, $this->huiles);
        $this->id['Écorché'] = $this->oeuvre('ecorche', 'Écorché', 1998, 2, $this->encres);
    }

    // ------------------------------------------------------ choix du mode

    public function test_l_ecran_des_oeuvres_propose_les_trois_classements(): void
    {
        $corps = $this->requete('GET', self::LISTE)->body;

        $this->assertStringContainsString('Classement de la page « Toutes les œuvres »', $corps);
        foreach (['libre', 'alphabetique', 'recentes'] as $mode) {
            $this->assertStringContainsString('name="mode" value="' . $mode . '"', $corps);
        }
        // Sans réglage : l'ordre libre.
        $this->assertMatchesRegularExpression('~name="mode" value="libre"\s+checked~', $corps);
    }

    public function test_l_ordre_libre_est_celui_de_depart(): void
    {
        $this->assertSame(['Zénith', 'Articulation', 'Écorché'], $this->ordrePublic());
        $this->assertSame(['Zénith', 'Articulation', 'Écorché'], $this->ordreDeLaListe());
    }

    public function test_choisir_l_ordre_alphabetique(): void
    {
        $reponse = $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'alphabetique']);

        $this->assertContains($reponse->status, [302, 303]);
        $this->assertSame(['Articulation', 'Écorché', 'Zénith'], $this->ordrePublic());
        // La liste d'administration montre le même ordre, sans rien à déplacer.
        $liste = $this->requete('GET', self::LISTE)->body;
        $this->assertSame(['Articulation', 'Écorché', 'Zénith'], self::titresDe($liste));
        $this->assertMatchesRegularExpression('~name="mode" value="alphabetique"\s+checked~', $liste);
        $this->assertStringNotContainsString('data-tri-id', $liste);
        $this->assertStringNotContainsString('name="direction"', $liste);
    }

    public function test_choisir_les_plus_recentes_d_abord(): void
    {
        $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'recentes']);

        $this->assertSame(['Articulation', 'Zénith', 'Écorché'], $this->ordrePublic());
        $this->assertSame(['Articulation', 'Zénith', 'Écorché'], $this->ordreDeLaListe());
    }

    public function test_un_classement_inconnu_est_refuse(): void
    {
        $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'alphabetique']);

        $reponse = $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'au-hasard']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(['Articulation', 'Écorché', 'Zénith'], $this->ordrePublic());
    }

    public function test_le_choix_entre_dans_l_historique(): void
    {
        $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'alphabetique']);
        $this->postAvecJeton(self::LISTE . '/classement', ['mode' => 'recentes']);

        $this->assertStringContainsString(
            'Classement de « Toutes les œuvres »',
            $this->requete('GET', '/cedric-taldu/admin/historique')->body,
        );
    }

    // ----------------------------------------- ordre libre : glisser-déposer

    public function test_la_liste_se_prete_au_glisser_deposer_en_ordre_libre(): void
    {
        $corps = $this->requete('GET', self::LISTE)->body;

        // Contrat du module admin-tri.js : un tableau, une ligne par œuvre, et
        // le formulaire que le module remplit puis poste.
        $this->assertStringContainsString('data-tri', $corps);
        $this->assertStringContainsString('data-tri-id="' . $this->id['Zénith'] . '"', $corps);
        $this->assertStringContainsString('action="' . self::LISTE . '/ordre"', $corps);
        $this->assertStringContainsString('name="portee" value="toutes"', $corps);
        $this->assertStringContainsString('name="ordre"', $corps);
    }

    public function test_un_nouvel_ordre_libre_s_applique_a_la_page(): void
    {
        $reponse = $this->postAvecJeton(self::LISTE . '/ordre', [
            'portee' => 'toutes',
            'ordre' => implode(',', [$this->id['Écorché'], $this->id['Zénith'], $this->id['Articulation']]),
        ]);

        $this->assertContains($reponse->status, [302, 303]);
        $this->assertSame(['Écorché', 'Zénith', 'Articulation'], $this->ordrePublic());
        $this->assertSame(['Écorché', 'Zénith', 'Articulation'], $this->ordreDeLaListe());
    }

    public function test_l_ordre_libre_ne_touche_pas_a_l_ordre_des_galeries(): void
    {
        $this->postAvecJeton(self::LISTE . '/ordre', [
            'portee' => 'toutes',
            'ordre' => implode(',', [$this->id['Écorché'], $this->id['Zénith'], $this->id['Articulation']]),
        ]);

        // Dans la galerie Encres, Zénith reste avant Écorché.
        $this->assertSame(
            ['Zénith', 'Écorché'],
            self::titresPublics($this->requete('GET', '/cedric-taldu/fr/galerie/encres')->body),
        );
    }

    public function test_un_ordre_incomplet_ou_falsifie_ne_perd_aucune_oeuvre(): void
    {
        // Identifiants inconnus, doublons, texte : ignorés. Les œuvres absentes
        // de l'envoi suivent, dans leur ordre actuel.
        $this->postAvecJeton(self::LISTE . '/ordre', [
            'portee' => 'toutes',
            'ordre' => $this->id['Écorché'] . ',999999,abc,' . $this->id['Écorché'],
        ]);

        $this->assertSame(['Écorché', 'Zénith', 'Articulation'], $this->ordrePublic());
    }

    public function test_les_fleches_deplacent_dans_l_ordre_libre(): void
    {
        // Sans JavaScript : ↑ et ↓, sur la liste sans filtre, agissent sur
        // l'ordre de « Toutes les œuvres ».
        $this->postAvecJeton(self::LISTE . '/' . $this->id['Écorché'] . '/position', [
            'direction' => 'haut',
            'portee' => 'toutes',
        ]);

        $this->assertSame(['Zénith', 'Écorché', 'Articulation'], $this->ordrePublic());
    }

    public function test_une_nouvelle_oeuvre_se_range_a_la_fin_de_l_ordre_libre(): void
    {
        $this->postAvecJeton(self::LISTE, [
            'rubrique' => (string) $this->encres,
            'reference' => 'CT-NOUV-001',
            'titre_fr' => 'Nouvelle venue',
        ]);

        $this->assertSame('Nouvelle venue', array_slice($this->ordreDeLaListe(), -1)[0]);
    }

    // ------------------------------------------- ordre d'une galerie

    public function test_la_liste_filtree_regle_l_ordre_de_la_galerie(): void
    {
        $liste = $this->requete('GET', self::LISTE . '?rubrique=' . $this->encres)->body;
        $this->assertSame(['Zénith', 'Écorché'], self::titresDe($liste));
        $this->assertStringContainsString('name="portee" value="' . $this->encres . '"', $liste);

        $this->postAvecJeton(self::LISTE . '/ordre', [
            'portee' => (string) $this->encres,
            // Articulation appartient à une autre galerie : ignorée.
            'ordre' => implode(',', [$this->id['Écorché'], $this->id['Articulation'], $this->id['Zénith']]),
        ]);

        $this->assertSame(
            ['Écorché', 'Zénith'],
            self::titresPublics($this->requete('GET', '/cedric-taldu/fr/galerie/encres')->body),
        );
        // … sans toucher à l'ordre libre de « Toutes les œuvres ».
        $this->assertSame(['Zénith', 'Articulation', 'Écorché'], $this->ordrePublic());
    }

    public function test_un_filtre_de_statut_suspend_le_reordonnancement(): void
    {
        // Réordonner une liste partielle serait ambigu : pas de poignée.
        $corps = $this->requete('GET', self::LISTE . '?statut=available')->body;

        $this->assertStringNotContainsString('data-tri-id', $corps);
    }

    // ------------------------------------------------------------- outils

    private function oeuvre(string $slug, string $titre, int $annee, int $rang, int $galerie): int
    {
        return (new ArtworkFactory($this->pdo))->published()->madeIn($annee)
            ->atPosition($rang)->atWorksPosition($rang)
            ->translated('fr', $slug, $titre)->create($galerie);
    }

    /**
     * @return list<string>
     */
    private function ordrePublic(): array
    {
        return self::titresPublics($this->requete('GET', self::PAGE)->body);
    }

    /**
     * @return list<string>
     */
    private function ordreDeLaListe(): array
    {
        return self::titresDe($this->requete('GET', self::LISTE)->body);
    }

    /**
     * Titres des œuvres d'une page publique, dans l'ordre d'affichage.
     *
     * @return list<string>
     */
    private static function titresPublics(string $corps): array
    {
        preg_match_all('~<a class="oeuvre[^"]*" href="[^"]*/oeuvre/([a-z-]+)"~', $corps, $m);
        $titres = ['zenith' => 'Zénith', 'articulation' => 'Articulation', 'ecorche' => 'Écorché'];

        return array_map(static fn (string $slug): string => $titres[$slug] ?? $slug, $m[1]);
    }

    /**
     * Titres de la liste d'administration, dans l'ordre des lignes.
     *
     * @return list<string>
     */
    private static function titresDe(string $corps): array
    {
        preg_match_all('~<a href="/cedric-taldu/admin/oeuvres/\d+">([^<]+)</a>~', $corps, $m);

        return array_map(static fn (string $t): string => html_entity_decode($t, ENT_QUOTES | ENT_HTML5), $m[1]);
    }
}
