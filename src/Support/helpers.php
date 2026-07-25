<?php

/**
 * Helpers d'echappement.
 *
 * Ce sont les SEULS moyens autorises d'ecrire une valeur dans un gabarit
 * (src/CLAUDE.md). EscapingTest scanne templates/ et fait echouer la suite des
 * qu'un « <?= » n'appelle pas l'un d'eux.
 *
 * Ces trois fonctions sont pures et sans etat : c'est ce qui justifie qu'elles
 * soient globales. La generation d'URL, elle, depend du prefixe de chemin resolu
 * par la requete : elle passe par l'objet UrlGenerator injecte dans les gabarits
 * sous le nom $url, et non par une fonction globale qui aurait exige un singleton.
 */

declare(strict_types=1);

/**
 * Contenu texte dans du HTML.
 *
 * ENT_QUOTES echappe aussi l'apostrophe, sans quoi une valeur placee dans un
 * attribut delimite par des apostrophes s'en echapperait. ENT_SUBSTITUTE evite
 * qu'un octet UTF-8 invalide ne vide silencieusement toute la valeur.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Valeur d'attribut HTML.
 *
 * Meme traitement que e() : avec ENT_QUOTES, les deux sortes de guillemets sont
 * neutralisees. Le nom distinct rend l'intention lisible dans les gabarits et
 * permet a EscapingTest de distinguer les contextes.
 */
function attr(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Donnees passees au JavaScript par un attribut data-*.
 *
 * Deux couches : les drapeaux JSON_HEX_* neutralisent « < », « > », « & »,
 * l'apostrophe et le guillemet a l'interieur des chaines, puis htmlspecialchars
 * protege les guillemets structurels du JSON lui-meme. Le resultat est donc sur
 * dans un attribut, quelle que soit la sorte de guillemets qui le delimite.
 *
 * Le JavaScript relit la valeur par element.dataset.* : le navigateur decode le
 * HTML, JSON.parse fait le reste.
 */
/**
 * Montant, formate selon la langue.
 *
 * Ne calcule RIEN : le formatage est la derniere etape, sur un montant deja
 * arrete cote serveur. Un prix absent — artworks.price_cents a NULL, œuvre non
 * vendable — rend une chaine vide et non « 0,00 € ».
 */
function money(?App\Domain\Money $amount, App\Domain\Locale $locale): string
{
    return $amount?->format($locale) ?? '';
}

/**
 * HTML riche DEJA ASSAINI, rendu tel quel.
 *
 * 06-securite §2 : « HTML riche (blog, descriptions) : liste blanche stricte de
 * balises et d'attributs, assainissement A L'ECRITURE, stockage de la version
 * assainie. » La lecture ne fait donc plus qu'afficher.
 *
 * Cette fonction ne protege RIEN par elle-meme : elle nomme un contrat. Le seul
 * usage legitime est une valeur passee par Service\Content\HtmlSanitizer avant
 * d'entrer en base — description de rubrique, texte de methode, corps d'article.
 * Toute autre valeur passe par e().
 *
 * Elle existe pour deux raisons : rendre le contrat visible a la lecture d'un
 * gabarit, et permettre a EscapingTest d'autoriser cette sortie-la et aucune
 * autre. Sans elle, la seule facon d'afficher du gras serait d'ouvrir la porte
 * a toutes les sorties brutes.
 */
function richText(?string $sanitizedHtml): string
{
    return $sanitizedHtml ?? '';
}

function jsonAttr(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    return htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Date longue formatee selon la langue (05-i18n-seo §4).
 *
 *   FR : « 1 juin 2026 »      EN : « June 1, 2026 »
 *
 * Fonction PURE et sans etat, comme money() : les noms de mois sont la logique
 * de formatage, au meme titre que les separateurs de Money::format(). Aucune
 * dependance a ICU/NumberFormatter, pour la stabilite des tests et du mutualise.
 * Le resultat est du texte simple ; les gabarits l'ecrivent via e(dateLong(...)).
 */
function dateLong(?DateTimeImmutable $date, App\Domain\Locale $locale): string
{
    if ($date === null) {
        return '';
    }

    $months = [
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    $month = $months[$locale->value][(int) $date->format('n')];

    return $locale === App\Domain\Locale::Fr
        ? $date->format('j') . ' ' . $month . ' ' . $date->format('Y')
        : $month . ' ' . $date->format('j') . ', ' . $date->format('Y');
}
