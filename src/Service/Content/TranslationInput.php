<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Domain\Locale;

/**
 * Lecture des champs traduisibles d'un formulaire d'administration.
 *
 * Les formulaires du back-office portent un champ par langue, suffixe :
 * `titre_fr`, `titre_en`, `description_fr`... 04-back-office §5 parle d'onglets
 * FR/EN ; ce sont des onglets d'affichage, le formulaire poste tout d'un coup.
 *
 * Deux regles y sont appliquees une fois pour toutes :
 *
 *  1. Le HTML riche est ASSAINI ICI, donc a l'ecriture (06-securite §2). Un
 *     controleur qui oublierait de le faire stockerait une charge active.
 *  2. Une langue dont le champ requis est vide n'est PAS ecrite. Le francais
 *     est obligatoire, l'anglais facultatif (01-modele §7.10) : une ligne
 *     anglaise vide ferait croire a une traduction existante et couperait le
 *     repli linguistique.
 */
final class TranslationInput
{
    public const TEXT = 'text';
    public const HTML = 'html';

    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

    /**
     * @param  array<string, string>            $post   champs bruts de la requete
     * @param  array<string, string>            $fields nom de colonne => nom de champ du formulaire
     * @param  array<string, string>            $kinds  nom de colonne => TEXT ou HTML
     * @param  string                           $required colonne dont le vide fait sauter la langue
     * @return array<string, array<string, string|null>> langue => colonnes
     */
    public function collect(array $post, array $fields, array $kinds, string $required): array
    {
        $translations = [];

        foreach (Locale::cases() as $locale) {
            $values = [];

            foreach ($fields as $column => $field) {
                $raw = trim($post[$field . '_' . $locale->value] ?? '');

                if ($raw === '') {
                    $values[$column] = null;
                    continue;
                }

                $values[$column] = ($kinds[$column] ?? self::TEXT) === self::HTML
                    ? $this->sanitizer->sanitize($raw)
                    : $raw;
            }

            // Le francais est obligatoire, l'anglais facultatif. Une ligne
            // anglaise sans titre ferait croire a une traduction existante et
            // couperait le repli de 05-i18n-seo §3.
            if (($values[$required] ?? null) === null) {
                continue;
            }

            $translations[$locale->value] = $values;
        }

        return $translations;
    }
}
