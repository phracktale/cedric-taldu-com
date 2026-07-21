<?php

declare(strict_types=1);

namespace App\Service\Content;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Assainissement du HTML riche, a l'ECRITURE.
 *
 * 04-back-office §9 et 06-securite §2. Deux decisions structurent la classe :
 *
 *  1. L'analyse passe par un VRAI ANALYSEUR HTML (DOMDocument) et non par des
 *     expressions regulieres. Une expression reguliere sur du HTML est un jeu
 *     perdu d'avance : « <scr<script>ipt> », « < script > » et les entites
 *     encodees contournent toutes les tentatives raisonnables. L'analyseur, lui,
 *     voit l'arbre que verra le navigateur — et c'est cet arbre qu'on filtre.
 *  2. LISTE BLANCHE stricte. Ce qui n'est pas nomme disparait. Une liste noire
 *     se complete sans fin, et chaque nouvelle balise du langage rouvre la
 *     porte.
 *
 * Le resultat est stocke tel quel : la lecture ne fait plus qu'afficher. La
 * contrepartie est qu'un contenu mal assaini le reste — d'ou l'exhaustivite des
 * tests, et l'idempotence, verifiee elle aussi.
 */
final class HtmlSanitizer
{
    /**
     * Balises conservees. Reprise mot pour mot de 04-back-office §9.
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'ul', 'ol', 'li',
        'h2', 'h3', 'blockquote', 'a', 'figure', 'figcaption', 'img',
    ];

    /**
     * Attributs conserves, par balise. Tout le reste tombe — y compris `style`,
     * `class` et les `data-*`, qui sont autant de facons de faire faire quelque
     * chose a la page.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /**
     * Balises dont le CONTENU disparait avec elles.
     *
     * Ailleurs on conserve le texte — retirer « <span>Amiens</span> » ne doit
     * pas faire disparaitre « Amiens ». Mais conserver le texte d'un <script>
     * afficherait le code de l'attaque au milieu de la page.
     */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = $this->parse($html);
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $this->clean($body);

        return trim($this->serialize($document, $body));
    }

    // ------------------------------------------------------------- analyse

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        // libxml traite le document comme du latin-1 sans declaration
        // explicite : « é » deviendrait « Ã© ». L'en-tete meta le lui dit, et
        // il est retire a la serialisation.
        $wrapped = '<?xml encoding="UTF-8"?><html><head>'
            . '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            . '</head><body>' . $html . '</body></html>';

        // LIBXML_NOERROR : un editeur riche produit couramment du HTML
        // malforme. Ce n'est pas une erreur a signaler, c'est le cas nominal —
        // et l'analyseur repare comme le ferait un navigateur.
        $document->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);

        return $document;
    }

    /**
     * Filtre l'arbre en profondeur.
     *
     * Le parcours se fait sur une COPIE de la liste d'enfants : retirer un nœud
     * pendant l'iteration sur une DOMNodeList vivante saute l'element suivant,
     * et laisse donc passer une balise sur deux.
     */
    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                // Commentaires, instructions de traitement : un commentaire
                // conditionnel est un vecteur ancien mais reel, et un
                // commentaire colle depuis un traitement de texte porte souvent
                // des metadonnees.
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->clean($child);
                $this->unwrap($child);
                continue;
            }

            $this->filterAttributes($child, $tag);
            $this->clean($child);
        }
    }

    /**
     * Remplace un element par ses enfants, en conservant le texte.
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private function filterAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (!$attribute instanceof DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'href' && !$this->isSafeHref($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'src' && !$this->isInternalPath($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /**
     * Destination de lien acceptable : https, mailto, ou chemin interne
     * (06-securite §2).
     */
    private function isSafeHref(string $url): bool
    {
        $normalized = self::normalizeUrl($url);

        if ($normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, '/')) {
            // « //ailleurs.test » est une URL absolue a protocole relatif, pas
            // un chemin interne.
            return !str_starts_with($normalized, '//');
        }

        // Une ancre ou un chemin relatif : pas de schema du tout.
        $colon = strpos($normalized, ':');

        if ($colon === false) {
            return true;
        }

        return in_array(substr($normalized, 0, $colon), ['https', 'mailto'], true);
    }

    /**
     * Source d'image : chemin interne UNIQUEMENT, plus strict qu'un href.
     *
     * 06-securite §9 : « Aucune ressource tierce chargee. » Une image distante
     * est un mouchard — elle transmet l'adresse IP et l'en-tete Referer du
     * visiteur a un tiers — et la CSP `img-src 'self' data:` la bloquerait de
     * toute facon, ne laissant qu'une image cassee. Autant ne pas la stocker.
     *
     * Les URI `data:` sont exclues elles aussi : la CSP les tolere pour les
     * images, mais rien ne garantit qu'un `data:` colle dans l'editeur porte
     * bien une image — un SVG y passerait.
     */
    private function isInternalPath(string $url): bool
    {
        $normalized = self::normalizeUrl($url);

        return $normalized !== ''
            && str_starts_with($normalized, '/')
            && !str_starts_with($normalized, '//');
    }

    /**
     * Forme comparable d'une URL.
     *
     * Espaces, tabulations, retours chariot et octets nuls retires, entites
     * decodees : « java\tscript: » et « &#106;avascript: » sont lus comme
     * « javascript: » par les navigateurs. C'est la forme obfusquee que
     * 06-securite §2 demande explicitement de rejeter.
     */
    private static function normalizeUrl(string $url): string
    {
        $decoded = strtolower(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return (string) preg_replace('/[\s\x00-\x1F\x7F]/', '', $decoded);
    }

    // -------------------------------------------------------- serialisation

    /**
     * Rend le contenu du <body> sans l'enrobage ajoute pour l'analyse.
     */
    private function serialize(DOMDocument $document, DOMNode $body): string
    {
        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return $html;
    }
}
