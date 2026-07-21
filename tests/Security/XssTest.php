<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\ArtworkFactory;
use Tests\Support\Factory\CategoryFactory;
use Tests\Support\Factory\MediaFactory;
use Tests\Support\Factory\SeriesFactory;
use Tests\Support\Factory\UserFactory;

/**
 * 06-securite §2, et tests/CLAUDE.md : « Injecte <script>, "><img onerror>,
 * javascript: dans CHAQUE champ persistable, puis verifie l'echappement dans
 * TOUTES les pages qui le reaffichent. »
 *
 * Le jeu de charges est parcouru pour chaque champ et chaque page : quand un
 * champ est ajoute au catalogue, il suffit de l'ajouter ici pour qu'il soit
 * couvert par toutes les charges d'un coup.
 */
final class XssTest extends AdminTestCase
{
    /**
     * Charges reprises de tests/Support/payloads.php (07-tests-tdd §4).
     *
     * @return iterable<string, array{string}>
     */
    public static function chargesXss(): iterable
    {
        yield 'balise script' => ['<script>alert(1)</script>'];
        yield 'sortie d’attribut' => ['"><img src=x onerror=alert(1)>'];
        yield 'sortie d’attribut à apostrophe' => ["'><svg onload=alert(1)>"];
        yield 'schéma javascript' => ['javascript:alert(document.cookie)'];
        yield 'gestionnaire inline' => ['" onmouseover="alert(1)'];
        yield 'fermeture de style' => ['</style><script>alert(1)</script>'];
        yield 'entité encodée' => ['&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'balise img' => ['<img src=x onerror="alert(1)">'];
    }

    /**
     * Aucune charge ne doit ressortir executable : ni balise, ni gestionnaire
     * d'evenement, ni schema javascript dans un attribut.
     */
    private function assertAucuneChargeActive(string $html, string $charge): void
    {
        $echappee = htmlspecialchars($charge, ENT_QUOTES, 'UTF-8');

        // 1. La charge a bien ete REAFFICHEE, sous sa forme echappee. Sans cette
        //    assertion, un champ simplement ignore par le gabarit ferait passer
        //    le test sans rien prouver.
        $this->assertStringContainsString($echappee, $html, 'La charge doit être réaffichée, échappée.');

        // 2. Elle n'apparait NULLE PART sous sa forme brute.
        //
        //    Chercher « onerror= » dans la page entiere serait faux : la forme
        //    echappee « &lt;img src=x onerror=... &gt; » contient cette chaine
        //    et elle est parfaitement inerte. Ce qui compte est que les
        //    caracteres structurants aient ete neutralises.
        if ($echappee !== $charge) {
            $this->assertStringNotContainsString($charge, $html, 'La charge ne doit jamais sortir non échappée.');
        }

        // 3. Aucun attribut ne peut porter un schema executable, quelle que soit
        //    la charge.
        $this->assertSame(0, preg_match('/(?:href|src|action)\s*=\s*["\']?\s*javascript:/i', $html));
    }

    // ----------------------------------------------------------- rubrique

    #[DataProvider('chargesXss')]
    public function test_le_titre_d_une_rubrique_est_echappe_sur_la_page_rubrique(string $charge): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', $charge)->create();

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/galerie/encres')->body, $charge);
    }

    #[DataProvider('chargesXss')]
    public function test_le_titre_d_une_rubrique_est_echappe_dans_le_menu(string $charge): void
    {
        // Le menu Galerie est present sur TOUTES les pages : une charge qui y
        // passerait serait active partout.
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', $charge)->create();

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/')->body, $charge);
    }

    #[DataProvider('chargesXss')]
    public function test_le_surtitre_d_une_rubrique_est_echappe(string $charge): void
    {
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres', $charge)->create();

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/galerie/encres')->body, $charge);
    }

    // -------------------------------------------------------------- œuvre

    #[DataProvider('chargesXss')]
    public function test_le_titre_d_une_œuvre_est_echappe_sur_sa_fiche(string $charge): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', $charge)->create($rubrique);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/oeuvre/articulation')->body, $charge);
    }

    #[DataProvider('chargesXss')]
    public function test_le_titre_d_une_œuvre_est_echappe_sur_la_grille(string $charge): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->translated('fr', 'articulation', $charge)->create($rubrique);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/galerie/encres')->body, $charge);
    }

    #[DataProvider('chargesXss')]
    public function test_la_technique_d_une_œuvre_est_echappee(string $charge): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new ArtworkFactory($this->pdo))->usingTechnique($charge)
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/oeuvre/articulation')->body, $charge);
    }

    #[DataProvider('chargesXss')]
    public function test_le_texte_alternatif_d_un_media_est_echappe(string $charge): void
    {
        // Le texte alternatif finit dans un ATTRIBUT : c'est le contexte ou une
        // charge « "> » est la plus dangereuse.
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        $media = (new MediaFactory($this->pdo))->named('visuel')->translated('fr', $charge)->create();
        (new ArtworkFactory($this->pdo))->withPrimaryMedia($media)
            ->translated('fr', 'articulation', 'Articulation')->create($rubrique);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/oeuvre/articulation')->body, $charge);
    }

    // -------------------------------------------------------------- série

    #[DataProvider('chargesXss')]
    public function test_le_titre_d_une_serie_est_echappe_dans_les_filtres(string $charge): void
    {
        $rubrique = (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();
        (new SeriesFactory($this->pdo))->translated('fr', 'piliers', $charge)->create($rubrique);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/galerie/encres')->body, $charge);
    }

    // ------------------------------------------------------------ réglages

    #[DataProvider('chargesXss')]
    public function test_un_reglage_editorial_est_echappe_sur_l_accueil(string $charge): void
    {
        $this->pdo->prepare('INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())')
            ->execute([
                'k' => 'home.hero',
                'v' => json_encode(['fr' => ['title' => $charge]], JSON_THROW_ON_ERROR),
            ]);

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/fr/')->body, $charge);
    }

    // ------------------------------------------------------------ reflété

    public function test_un_parametre_d_url_n_est_jamais_reflete_dans_la_page(): void
    {
        // Le filtre de série arrive par « ?serie=… ». Une charge y est refusée
        // par le format de slug, mais elle ne doit surtout pas être réaffichée.
        (new CategoryFactory($this->pdo))->translated('fr', 'encres', 'Encres')->create();

        $corps = $this->get('/cedric-taldu/fr/galerie/encres?serie=%22%3E%3Cscript%3Ealert(1)%3C/script%3E')->body;

        $this->assertStringNotContainsString('<script>alert', $corps);
        $this->assertStringNotContainsString('alert(1)', $corps);
    }

    public function test_un_slug_malveillant_dans_le_chemin_ne_ressort_pas(): void
    {
        $corps = $this->get('/cedric-taldu/fr/oeuvre/%3Cscript%3E')->body;

        $this->assertStringNotContainsString('<script>', $corps);
    }

    // ------------------------------------------------ aller-retour complet

    /**
     * Le HTML riche est la SEULE sortie non echappee du projet (helper
     * richText, autorise par EscapingTest). Sa surete ne repose pas sur
     * l'echappement mais sur l'assainissement a l'ecriture — il faut donc
     * l'eprouver de bout en bout, par le VRAI formulaire d'administration
     * jusqu'a la page publique, et non en inserant une valeur deja propre.
     */
    #[DataProvider('chargesXss')]
    public function test_une_charge_dans_la_description_d_une_rubrique_ressort_inerte(string $charge): void
    {
        $this->connecte();

        $this->postAvecJeton('/cedric-taldu/admin/rubriques', [
            'titre_fr' => 'Encres',
            'slug_fr' => 'encres',
            'description_fr' => '<p>Introduction</p>' . $charge,
        ]);

        $id = (int) $this->pdo->query('SELECT id FROM categories')?->fetchColumn();
        $this->postAvecJeton('/cedric-taldu/admin/rubriques/' . $id . '/publication');

        $this->assertPageInerte($this->get('/cedric-taldu/fr/galerie/encres')->body);
    }

    #[DataProvider('chargesXss')]
    public function test_une_charge_dans_le_texte_de_methode_ressort_inerte(string $charge): void
    {
        $this->connecte();

        $this->postAvecJeton('/cedric-taldu/admin/rubriques', [
            'titre_fr' => 'Encres',
            'slug_fr' => 'encres',
            'methode_fr' => '<p>Le geste</p>' . $charge,
        ]);

        $id = (int) $this->pdo->query('SELECT id FROM categories')?->fetchColumn();
        $this->postAvecJeton('/cedric-taldu/admin/rubriques/' . $id . '/publication');

        $this->assertPageInerte($this->get('/cedric-taldu/fr/galerie/encres')->body);
    }

    #[DataProvider('chargesXss')]
    public function test_une_charge_est_reaffichee_inerte_dans_le_back_office(string $charge): void
    {
        // tests/CLAUDE.md : « verifie l'echappement dans TOUTES les pages qui le
        // reaffichent (public ET back-office) ». Un formulaire d'edition qui
        // reafficherait la charge dans un attribut `value` serait une XSS
        // stockee visant l'artiste lui-meme.
        $this->connecte();

        $this->postAvecJeton('/cedric-taldu/admin/rubriques', [
            'titre_fr' => $charge,
            'slug_fr' => 'encres',
        ]);

        $id = (int) $this->pdo->query('SELECT id FROM categories')?->fetchColumn();

        $this->assertAucuneChargeActive($this->get('/cedric-taldu/admin/rubriques/' . $id)->body, $charge);
        $this->assertAucuneChargeActive($this->get('/cedric-taldu/admin/rubriques')->body, $charge);
    }

    /**
     * Aucune balise active, aucun gestionnaire d'evenement, aucun schema
     * executable — quelle que soit la forme sous laquelle la charge a survecu.
     *
     * L'inspection porte sur l'ARBRE et non sur le texte de la page. Chercher
     * « onmouseover= » dans le HTML rendu serait faux : la charge
     * `" onmouseover="alert(1)` echouee en CONTENU TEXTUEL contient bien cette
     * chaine, et elle y est parfaitement inerte — un guillemet dans du texte ne
     * sort d'aucune balise. Ce qui compte est qu'aucun ELEMENT ne porte un tel
     * attribut, et c'est ce que l'analyseur, seul, sait dire.
     */
    private function assertPageInerte(string $html): void
    {
        // Le corps de page seulement : la mise en page porte un <script
        // type="module"> legitime, avec nonce.
        $contenu = (string) preg_replace('#.*<main[^>]*>(.*)</main>.*#s', '$1', $html);

        $document = new \DOMDocument();
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>' . $contenu . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        foreach ((new \DOMXPath($document))->query('//*') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            $this->assertNotContains(
                strtolower($element->nodeName),
                ['script', 'style', 'iframe', 'object', 'embed', 'form'],
                'Balise active rendue par la page.',
            );

            foreach ($element->attributes ?? [] as $attribut) {
                $nom = strtolower($attribut->nodeName);

                $this->assertSame(0, preg_match('/^on[a-z]+$/', $nom), 'Gestionnaire d’événement : ' . $nom);

                if ($nom === 'href' || $nom === 'src') {
                    $this->assertSame(
                        0,
                        preg_match('/^\s*(javascript|data|vbscript):/i', (string) $attribut->nodeValue),
                        'Schéma exécutable dans ' . $nom,
                    );
                }
            }
        }
    }

    private function connecte(): void
    {
        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
    }
}
