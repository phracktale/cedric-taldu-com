<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use PDO;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §5 : CRUD des œuvres.
 *
 * Critere de fin du lot 2 : « l'artiste peut creer [...] une œuvre de bout en
 * bout sans toucher au code ». Ce fichier decrit ce parcours, et surtout les
 * quatre garde-fous du §5 — publication sans image, prix absent sur une œuvre
 * disponible, passage manuel a « vendue », apercu avant publication — dont
 * chacun protege l'artiste d'une page publique incoherente.
 */
final class OeuvresTest extends AdminTestCase
{
    private const OEUVRES = '/cedric-taldu/admin/oeuvres';

    private int $rubrique;

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');

        $this->rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
    }

    // ------------------------------------------------------------- creation

    public function test_le_formulaire_de_creation_s_ouvre(): void
    {
        $reponse = $this->get(self::OEUVRES . '/nouvelle');

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('name="reference"', $reponse->body);
        $this->assertStringContainsString('name="titre_fr"', $reponse->body);
    }

    public function test_une_oeuvre_se_cree_avec_une_reference_une_rubrique_et_un_titre(): void
    {
        $reponse = $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation']);

        $this->assertSame(302, $reponse->status);
        $this->assertSame(1, $this->compter('artworks'));
        $this->assertSame('CT-ENC-001', $this->valeur('SELECT reference FROM artworks'));
    }

    public function test_une_oeuvre_nait_en_brouillon_et_non_publiee(): void
    {
        // Rien ne se publie par accident : la fiche est invisible tant que
        // l'artiste ne l'a pas decidee, et un brouillon repond 404 (06-securite §8).
        $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation']);

        $this->assertSame('draft', $this->valeur('SELECT status FROM artworks'));
        $this->assertSame('0', $this->valeur('SELECT is_published FROM artworks'));
    }

    public function test_une_reference_deja_prise_est_refusee_sans_erreur_sql(): void
    {
        // `artworks.reference` est UNIQUE : sans controle applicatif, le
        // doublon ferait tomber la page sur une PDOException.
        (new ArtworkFactory($this->pdo))->withReference('CT-ENC-001')
            ->translated('fr', 'existante', 'Existante')->create($this->rubrique);

        $reponse = $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation']);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(1, $this->compter('artworks'));
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
    }

    public function test_une_reference_vide_est_refusee(): void
    {
        $this->assertSame(422, $this->creer(['reference' => '', 'titre_fr' => 'Articulation'])->status);
    }

    public function test_une_rubrique_inexistante_est_refusee(): void
    {
        // Sans ce controle, la cle etrangere ferait tomber la page.
        $reponse = $this->postAvecJeton(self::OEUVRES, [
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'rubrique' => '999999',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->compter('artworks'));
    }

    // ------------------------------------------------------------ prix

    public function test_le_prix_est_saisi_en_euros_et_stocke_en_centimes(): void
    {
        // 04-back-office §5 : « Prix TTC en euros (saisi en euros, stocke en
        // centimes) ». Aucun flottant n'intervient : « 450,50 » devient 45050.
        $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation', 'prix' => '450,50']);

        $this->assertSame('45050', $this->valeur('SELECT price_cents FROM artworks'));
    }

    public function test_le_prix_accepte_le_point_comme_la_virgule(): void
    {
        $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation', 'prix' => '450.5']);

        $this->assertSame('45050', $this->valeur('SELECT price_cents FROM artworks'));
    }

    public function test_un_prix_vide_laisse_l_oeuvre_non_vendable(): void
    {
        // price_cents NULL signifie « non vendable » (01-modele §3), ce qui
        // n'est pas la meme chose que gratuite.
        $this->creer(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation', 'prix' => '']);

        $this->assertNull($this->valeur('SELECT price_cents FROM artworks'));
    }

    public function test_un_prix_illisible_est_refuse(): void
    {
        $reponse = $this->creer([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'prix' => 'quatre cents',
        ]);

        $this->assertSame(422, $reponse->status);
    }

    // ------------------------------------------------------- garde-fous

    public function test_publier_une_oeuvre_sans_image_principale_est_impossible(): void
    {
        // 04-back-office §5 : « Publier une œuvre sans image principale est
        // impossible. » Une fiche sans visuel n'a aucun interet et casse la
        // grille de la rubrique.
        $id = $this->creerEtLire(['reference' => 'CT-ENC-001', 'titre_fr' => 'Articulation']);

        $reponse = $this->postAvecJeton(self::OEUVRES . '/' . $id . '/publication');

        $this->assertSame(409, $reponse->status);
        $this->assertSame('0', $this->valeur('SELECT is_published FROM artworks'));
    }

    public function test_publier_une_oeuvre_avec_image_est_possible(): void
    {
        $media = (new MediaFactory($this->pdo))->named('visuel')->create();
        $id = $this->creerEtLire([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'image_principale' => (string) $media,
            'statut' => 'available',
            'prix' => '450',
        ]);

        $reponse = $this->postAvecJeton(self::OEUVRES . '/' . $id . '/publication');

        $this->assertSame(302, $reponse->status);
        $this->assertSame('1', $this->valeur('SELECT is_published FROM artworks'));
    }

    public function test_une_oeuvre_disponible_sans_prix_est_refusee(): void
    {
        // 04-back-office §5 : « Prix vide + statut "disponible" -> avertissement
        // BLOQUANT : l'œuvre serait affichee disponible sans pouvoir etre
        // achetee. »
        $reponse = $this->creer([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'statut' => 'available',
            'prix' => '',
        ]);

        $this->assertSame(422, $reponse->status);
        $this->assertSame(0, $this->compter('artworks'));
    }

    public function test_une_oeuvre_non_destinee_a_la_vente_n_a_pas_besoin_de_prix(): void
    {
        $reponse = $this->creer([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'statut' => 'not_for_sale',
            'prix' => '',
        ]);

        $this->assertSame(302, $reponse->status);
    }

    public function test_le_passage_manuel_a_vendue_est_autorise_et_journalise(): void
    {
        // 04-back-office §5 : « Le passage manuel en "vendue" est autorise
        // (vente en atelier, en salon) et journalise. »
        $id = $this->creerEtLire([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'statut' => 'available',
            'prix' => '450',
        ]);

        $this->modifier($id, [
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'slug_fr' => 'articulation',
            'statut' => 'sold',
            'prix' => '450',
        ]);

        $this->assertSame('sold', $this->valeur('SELECT status FROM artworks'));
        $this->assertSame(1, (int) $this->valeur(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'artwork.status_changed'"
        ));
    }

    // --------------------------------------------------------------- edition

    public function test_le_formulaire_d_edition_porte_les_valeurs_existantes(): void
    {
        $id = (new ArtworkFactory($this->pdo))->withReference('CT-ENC-001')->priced(45000)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $reponse = $this->get(self::OEUVRES . '/' . $id);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('value="CT-ENC-001"', $reponse->body);
        $this->assertStringContainsString('value="Articulation"', $reponse->body);
        // Le prix est reaffiche EN EUROS, comme il a ete saisi.
        $this->assertStringContainsString('value="450.00"', $reponse->body);
    }

    public function test_une_oeuvre_inexistante_repond_404(): void
    {
        $this->assertSame(404, $this->get(self::OEUVRES . '/999999')->status);
    }

    public function test_une_oeuvre_se_modifie(): void
    {
        $id = (new ArtworkFactory($this->pdo))->withReference('CT-ENC-001')
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->modifier($id, [
            'reference' => 'CT-ENC-002',
            'titre_fr' => 'Articulation II',
            'slug_fr' => 'articulation',
            'technique' => 'Encre de Chine sur papier',
            'largeur' => '400',
            'hauteur' => '600',
            'annee' => '2026',
        ]);

        $this->assertSame('CT-ENC-002', $this->valeur('SELECT reference FROM artworks'));
        $this->assertSame('Encre de Chine sur papier', $this->valeur('SELECT technique FROM artworks'));
        $this->assertSame('400', $this->valeur('SELECT width_mm FROM artworks'));
        $this->assertSame('2026', $this->valeur('SELECT year FROM artworks'));
    }

    public function test_la_description_est_assainie_a_l_ecriture(): void
    {
        $this->creer([
            'reference' => 'CT-ENC-001',
            'titre_fr' => 'Articulation',
            'description_fr' => '<p>Pièce unique</p><script>alert(1)</script>',
        ]);

        $this->assertSame('<p>Pièce unique</p>', $this->valeur('SELECT description FROM artwork_translations'));
    }

    // ---------------------------------------------------------------- liste

    public function test_la_liste_montre_les_brouillons(): void
    {
        (new ArtworkFactory($this->pdo))->draft()->published(false)
            ->translated('fr', 'brouillon', 'Un brouillon')->create($this->rubrique);

        $reponse = $this->get(self::OEUVRES);

        $this->assertSame(200, $reponse->status);
        $this->assertStringContainsString('Un brouillon', $reponse->body);
    }

    public function test_la_liste_se_filtre_par_rubrique(): void
    {
        $autre = (new CategoryFactory($this->pdo))->translated('fr', 'peintures', 'Peintures')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'encre', 'Une encre')->create($this->rubrique);
        (new ArtworkFactory($this->pdo))->translated('fr', 'peinture', 'Une peinture')->create($autre);

        $reponse = $this->get(self::OEUVRES . '?rubrique=' . $this->rubrique);

        $this->assertStringContainsString('Une encre', $reponse->body);
        $this->assertStringNotContainsString('Une peinture', $reponse->body);
    }

    public function test_un_filtre_invalide_ne_fait_pas_tomber_la_liste(): void
    {
        $reponse = $this->get(self::OEUVRES . '?rubrique=' . rawurlencode("' OR '1'='1"));

        $this->assertSame(200, $reponse->status);
        $this->assertStringNotContainsString('SQLSTATE', $reponse->body);
    }

    // --------------------------------------------------------------- apercu

    public function test_une_oeuvre_non_publiee_repond_404_sans_jeton(): void
    {
        (new ArtworkFactory($this->pdo))->draft()->published(false)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->assertSame(404, $this->get('/cedric-taldu/fr/oeuvre/articulation')->status);
    }

    public function test_un_lien_d_apercu_ouvre_une_oeuvre_non_publiee(): void
    {
        // 04-back-office §5 : « Apercu avant publication via un lien signe a
        // duree limitee (?preview=<jeton>). » C'est ce qui permet a l'artiste de
        // montrer une fiche a quelqu'un avant de la publier.
        $media = (new MediaFactory($this->pdo))->named('visuel')->create();
        $id = (new ArtworkFactory($this->pdo))->draft()->published(false)->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $lien = $this->lienDApercu($id);

        $this->assertSame(200, $this->get($lien)->status);
    }

    public function test_un_jeton_d_apercu_falsifie_ne_donne_rien(): void
    {
        (new ArtworkFactory($this->pdo))->draft()->published(false)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $reponse = $this->get('/cedric-taldu/fr/oeuvre/articulation?preview=' . str_repeat('a', 64));

        $this->assertSame(404, $reponse->status);
    }

    public function test_un_jeton_d_apercu_expire_ne_donne_plus_rien(): void
    {
        // Duree limitee : un lien partage une fois ne doit pas rester valable
        // indefiniment (06-securite §8).
        $media = (new MediaFactory($this->pdo))->named('visuel')->create();
        $id = (new ArtworkFactory($this->pdo))->draft()->published(false)->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $lien = $this->lienDApercu($id);

        $this->horloge->advance('+25 hours');

        $this->assertSame(404, $this->get($lien)->status);
    }

    public function test_le_jeton_d_une_oeuvre_n_ouvre_pas_une_autre(): void
    {
        $media = (new MediaFactory($this->pdo))->named('visuel')->create();
        $premiere = (new ArtworkFactory($this->pdo))->draft()->published(false)->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);
        (new ArtworkFactory($this->pdo))->draft()->published(false)
            ->translated('fr', 'seconde', 'Seconde')->create($this->rubrique);

        $jeton = $this->jetonDe($this->lienDApercu($premiere));

        $this->assertSame(404, $this->get('/cedric-taldu/fr/oeuvre/seconde?preview=' . $jeton)->status);
    }

    // --------------------------------------------------------- suppression

    public function test_une_oeuvre_se_supprime(): void
    {
        $id = (new ArtworkFactory($this->pdo))
            ->translated('fr', 'articulation', 'Articulation')->create($this->rubrique);

        $this->postAvecJeton(self::OEUVRES . '/' . $id . '/suppression');

        $this->assertSame(0, $this->compter('artworks'));
        $this->assertSame(0, $this->compter('artwork_translations'));
    }

    // --------------------------------------------------------------- outils

    /**
     * @param array<string, string> $champs
     */
    private function creer(array $champs): \App\Core\Response
    {
        return $this->postAvecJeton(self::OEUVRES, [
            'rubrique' => (string) $this->rubrique,
            ...$champs,
        ]);
    }

    /**
     * @param array<string, string> $champs
     */
    private function creerEtLire(array $champs): int
    {
        $this->creer($champs);

        return (int) $this->valeur('SELECT id FROM artworks ORDER BY id DESC LIMIT 1');
    }

    /**
     * @param array<string, string> $champs
     */
    private function modifier(int $id, array $champs): \App\Core\Response
    {
        return $this->postAvecJeton(self::OEUVRES . '/' . $id, [
            'rubrique' => (string) $this->rubrique,
            ...$champs,
        ]);
    }

    /**
     * Le lien d'apercu est produit par le back-office lui-meme : le test ne le
     * fabrique pas, il le LIT sur la page d'edition. Un lien qu'on forge dans le
     * test ne prouverait rien sur celui que l'artiste recoit.
     */
    private function lienDApercu(int $id): string
    {
        $page = $this->get(self::OEUVRES . '/' . $id)->body;

        $this->assertSame(1, preg_match('/href="([^"]*\?preview=[0-9a-f-]+)"/', $page, $trouve));

        return html_entity_decode($trouve[1], ENT_QUOTES, 'UTF-8');
    }

    private function jetonDe(string $lien): string
    {
        $this->assertSame(1, preg_match('/preview=([0-9a-f-]+)/', $lien, $trouve));

        return $trouve[1];
    }

    private function compter(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);
        $valeur = $statement->fetchColumn();

        return $valeur === false || $valeur === null ? null : (string) $valeur;
    }
}
