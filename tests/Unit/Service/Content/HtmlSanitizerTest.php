<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Content;

use App\Service\Content\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 06-securite §2 et 04-back-office §9 : « liste blanche stricte de balises et
 * d'attributs, assainissement A L'ECRITURE, stockage de la version assainie ».
 *
 * L'assainissement a l'ecriture est un choix structurant : la lecture ne fait
 * plus qu'afficher, et une faille de l'assainisseur decouverte plus tard ne
 * transforme pas d'un coup tout le contenu deja stocke en charge active. En
 * contrepartie, ce qui est enleve l'est definitivement — d'ou une liste blanche
 * qu'on ouvre a regret plutot qu'une liste noire qu'on complete sans fin.
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $assainisseur;

    protected function setUp(): void
    {
        $this->assainisseur = new HtmlSanitizer();
    }

    // ------------------------------------------------------------- conserve

    #[DataProvider('balisesAutorisees')]
    public function test_une_balise_de_la_liste_blanche_est_conservee(string $html): void
    {
        $this->assertSame($html, $this->assainisseur->sanitize($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function balisesAutorisees(): iterable
    {
        yield 'paragraphe' => ['<p>Encre de Chine sur papier.</p>'];
        yield 'emphase' => ['<p><strong>Pièce unique</strong>, <em>signée</em>.</p>'];
        yield 'saut de ligne' => ['<p>Amiens<br>2026</p>'];
        yield 'liste à puces' => ['<ul><li>Encre</li><li>Papier</li></ul>'];
        yield 'liste ordonnée' => ['<ol><li>Premier</li></ol>'];
        yield 'titres' => ['<h2>La méthode</h2><h3>Le geste</h3>'];
        yield 'citation' => ['<blockquote><p>Le trait précède l’idée.</p></blockquote>'];
        yield 'figure' => ['<figure><img src="/media/a-320.jpg" alt="Encre"><figcaption>Détail</figcaption></figure>'];
    }

    public function test_le_texte_sans_balise_traverse_intact(): void
    {
        $this->assertSame('Encre de Chine, 2026.', $this->assainisseur->sanitize('Encre de Chine, 2026.'));
    }

    public function test_les_accents_et_apostrophes_typographiques_survivent(): void
    {
        // La collation est utf8mb4 et le site est en francais : un assainisseur
        // qui encoderait « é » en entite rendrait le contenu illisible a
        // l'edition suivante.
        $html = '<p>Œuvre réalisée à l’encre — 2026.</p>';

        $this->assertSame($html, $this->assainisseur->sanitize($html));
    }

    // -------------------------------------------------------------- retire

    #[DataProvider('chargesActives')]
    public function test_une_charge_active_est_retiree(string $html, string $interdit): void
    {
        $this->assertStringNotContainsString($interdit, $this->assainisseur->sanitize($html));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function chargesActives(): iterable
    {
        yield 'script' => ['<p>Bonjour</p><script>alert(1)</script>', 'alert'];
        yield 'style' => ['<style>body{display:none}</style><p>x</p>', 'display'];
        yield 'iframe' => ['<iframe src="https://ailleurs.test"></iframe>', 'iframe'];
        yield 'objet' => ['<object data="x.swf"></object>', 'object'];
        yield 'formulaire' => ['<form action="https://ailleurs.test"><input name="a"></form>', 'form'];
        yield 'gestionnaire onclick' => ['<p onclick="alert(1)">x</p>', 'onclick'];
        yield 'gestionnaire onerror' => ['<img src="x" onerror="alert(1)" alt="">', 'onerror'];
        yield 'gestionnaire onload' => ['<img src="/a.jpg" onload="alert(1)" alt="">', 'onload'];
        yield 'attribut style' => ['<p style="position:fixed">x</p>', 'style'];
        yield 'attribut de données' => ['<p data-charge="alert(1)">x</p>', 'data-charge'];
    }

    public function test_le_texte_d_une_balise_retiree_est_conserve(): void
    {
        // Retirer la balise sans son texte ferait disparaitre du contenu
        // legitime : « <span>Amiens</span> » doit rendre « Amiens ».
        $this->assertSame(
            '<p>Amiens, 2026</p>',
            $this->assainisseur->sanitize('<p><span>Amiens</span>, 2026</p>'),
        );
    }

    public function test_le_contenu_d_un_script_est_supprime_avec_lui(): void
    {
        // L'exception a la regle precedente : conserver le TEXTE d'un script
        // afficherait le code source de l'attaque au milieu de la page.
        $this->assertSame(
            '<p>Bonjour</p>',
            $this->assainisseur->sanitize('<p>Bonjour</p><script>alert(1)</script>'),
        );
    }

    // ---------------------------------------------------------------- liens

    public function test_un_lien_https_est_conserve(): void
    {
        $html = '<p><a href="https://cedrictaldu.com">Le site</a></p>';

        $this->assertSame($html, $this->assainisseur->sanitize($html));
    }

    public function test_un_lien_mailto_est_conserve(): void
    {
        $html = '<p><a href="mailto:contact@example.test">Écrire</a></p>';

        $this->assertSame($html, $this->assainisseur->sanitize($html));
    }

    public function test_un_lien_interne_est_conserve(): void
    {
        $html = '<p><a href="/fr/galerie/encres">Les encres</a></p>';

        $this->assertSame($html, $this->assainisseur->sanitize($html));
    }

    #[DataProvider('schemasInterdits')]
    public function test_un_schema_interdit_est_retire_sans_perdre_le_texte(string $href): void
    {
        // 06-securite §2 : « javascript:, data: et vbscript: sont rejetes, y
        // compris sous forme obfusquee. » Le lien devient du texte : on ne
        // supprime pas la phrase de l'artiste parce qu'un lien est mauvais.
        $resultat = $this->assainisseur->sanitize('<p><a href="' . $href . '">Cliquez</a></p>');

        $this->assertStringNotContainsString('href', $resultat);
        $this->assertStringContainsString('Cliquez', $resultat);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemasInterdits(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript en majuscules' => ['JavaScript:alert(1)'];
        yield 'javascript espace' => ['java script:alert(1)'];
        yield 'javascript tabulé' => ["java\tscript:alert(1)"];
        yield 'javascript avec espaces en tête' => ['   javascript:alert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'http en clair' => ['http://ailleurs.test'];
        yield 'protocole relatif' => ['//ailleurs.test/x'];
    }

    public function test_un_lien_http_est_refuse_mais_pas_un_lien_https(): void
    {
        // Le site est integralement en HTTPS : un lien en clair declencherait un
        // avertissement de contenu mixte et casserait upgrade-insecure-requests.
        $this->assertStringNotContainsString(
            'href',
            $this->assainisseur->sanitize('<p><a href="http://exemple.test">x</a></p>'),
        );
        $this->assertStringContainsString(
            'href',
            $this->assainisseur->sanitize('<p><a href="https://exemple.test">x</a></p>'),
        );
    }

    // ----------------------------------------------------------- attributs

    public function test_seuls_les_attributs_de_la_liste_blanche_survivent(): void
    {
        $resultat = $this->assainisseur->sanitize(
            '<img src="/media/a.jpg" alt="Encre" width="320" height="240" srcset="x" loading="lazy">'
        );

        $this->assertStringContainsString('src="/media/a.jpg"', $resultat);
        $this->assertStringContainsString('alt="Encre"', $resultat);
        $this->assertStringContainsString('width="320"', $resultat);
        $this->assertStringNotContainsString('srcset', $resultat);
        $this->assertStringNotContainsString('loading', $resultat);
    }

    public function test_une_image_pointant_une_origine_tierce_est_retiree(): void
    {
        // 06-securite §9 : « Aucune ressource tierce chargee. » Une image
        // distante est un mouchard, et la CSP la bloquerait de toute facon.
        $resultat = $this->assainisseur->sanitize('<p><img src="https://ailleurs.test/pixel.gif" alt=""></p>');

        $this->assertStringNotContainsString('ailleurs.test', $resultat);
    }

    // -------------------------------------------------------- robustesse

    public function test_du_html_malforme_ne_fait_pas_tomber_l_assainisseur(): void
    {
        // Un editeur riche produit regulierement des balises non fermees. Le
        // resultat doit rester du HTML valide, sans balise ouverte.
        $resultat = $this->assainisseur->sanitize('<p>Un texte <strong>gras');

        $this->assertSame('<p>Un texte <strong>gras</strong></p>', $resultat);
    }

    public function test_une_chaine_vide_reste_vide(): void
    {
        $this->assertSame('', $this->assainisseur->sanitize(''));
        $this->assertSame('', $this->assainisseur->sanitize('   '));
    }

    public function test_les_commentaires_html_sont_retires(): void
    {
        // Un commentaire conditionnel est un vecteur ancien mais reel, et un
        // commentaire colle depuis un traitement de texte porte souvent des
        // metadonnees.
        $this->assertSame(
            '<p>Texte</p>',
            $this->assainisseur->sanitize('<!--[if IE]><script>alert(1)</script><![endif]--><p>Texte</p>'),
        );
    }

    public function test_l_assainissement_est_idempotent(): void
    {
        // Assainir deux fois doit donner le meme resultat : le contenu est
        // reassaini a chaque enregistrement, et une transformation qui derive
        // degraderait le texte a chaque passage.
        $sale = '<p onclick="x">Bonjour <span>Amiens</span> <a href="javascript:alert(1)">ici</a></p>';

        $premier = $this->assainisseur->sanitize($sale);

        $this->assertSame($premier, $this->assainisseur->sanitize($premier));
    }

    #[DataProvider('chargesXssClassiques')]
    public function test_aucune_charge_xss_ne_ressort_active(string $charge): void
    {
        $resultat = $this->assainisseur->sanitize($charge);

        $this->assertSame(0, preg_match('/<\s*(script|style|iframe|object|embed|form)/i', $resultat));
        $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $resultat));
        $this->assertSame(0, preg_match('/(?:href|src)\s*=\s*["\']?\s*(?:javascript|data|vbscript):/i', $resultat));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chargesXssClassiques(): iterable
    {
        yield 'script simple' => ['<script>alert(1)</script>'];
        yield 'sortie d’attribut' => ['"><img src=x onerror=alert(1)>'];
        yield 'svg onload' => ['<svg onload=alert(1)>'];
        yield 'balise majuscule' => ['<SCRIPT>alert(1)</SCRIPT>'];
        yield 'balise espacée' => ['< script >alert(1)< /script >'];
        yield 'attribut sans guillemets' => ['<a href=javascript:alert(1)>x</a>'];
        yield 'entité encodée' => ['<a href="&#106;avascript:alert(1)">x</a>'];
        yield 'balise imbriquée' => ['<scr<script>ipt>alert(1)</script>'];
        yield 'iframe srcdoc' => ['<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>'];
    }
}
