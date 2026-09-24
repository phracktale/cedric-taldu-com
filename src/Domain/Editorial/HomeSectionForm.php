<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Contenu éditable des sections de l'accueil (revue du 2026-09-24).
 *
 * Jusqu'ici, seuls l'ordre et l'activation des sections s'éditaient
 * ({@see HomeLayout}) ; leur contenu ne venait que du jeu de démonstration.
 * Cette classe décrit les champs de chaque section et fait l'aller-retour
 * entre le formulaire (champs scalaires à plat, `titre_fr`, `cta_target`…) et
 * le document du réglage `home.*` :
 *
 *     { "fr": {…textes…}, "en": {…textes…}, "common": {…non traduit…} }
 *
 * `common` porte ce qui ne se traduit pas : réglages du CTA, fond du hero,
 * portrait de l'atelier. Les autres clefs du document sont préservées.
 */
final class HomeSectionForm
{
    /** Section éditable → clef du réglage. */
    public const SECTIONS = [
        'hero' => 'home.hero',
        'vitrine' => 'home.showcase',
        'triptyque' => 'home.triptych',
        'boutique' => 'home.shop',
        'atelier' => 'home.studio',
        'actus' => 'home.news',
        'contact' => 'home.contact',
    ];

    /**
     * Champs texte traduits : nom → [libellé, type, longueur maximale].
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: int}>>
     */
    public const TEXTS = [
        'hero' => [
            'eyebrow' => ['Surtitre', 'text', self::SHORT],
            'title' => ['Titre principal (H1)', 'text', self::SHORT],
            'baseline' => ['Accroche', 'textarea', self::LONG],
        ],
        'vitrine' => [],
        'triptyque' => [
            'eyebrow' => ['Surtitre', 'text', self::SHORT],
            'title' => ['Titre', 'text', self::SHORT],
            'intro' => ['Introduction', 'textarea', self::LONG],
        ],
        'boutique' => [
            'eyebrow' => ['Surtitre', 'text', self::SHORT],
            'title' => ['Titre', 'text', self::SHORT],
            'text' => ['Texte', 'textarea', self::LONG],
        ],
        'atelier' => [
            'eyebrow' => ['Surtitre', 'text', self::SHORT],
            'title' => ['Titre', 'text', self::SHORT],
            'lead' => ['Chapeau', 'textarea', self::LONG],
            'paragraphs' => ['Paragraphes (séparés par une ligne vide)', 'textarea', self::LONG * 3],
        ],
        'actus' => [
            'title' => ['Titre', 'text', self::SHORT],
        ],
        'contact' => [
            'eyebrow' => ['Surtitre', 'text', self::SHORT],
            'title' => ['Titre', 'text', self::SHORT],
            'text' => ['Texte', 'textarea', self::LONG],
        ],
    ];

    /**
     * Sections dotées d'un CTA, réglages communs par défaut (comportement historique)
     * et clef de traduction du libellé par défaut.
     *
     * @var array<string, array{target: string, style: string, label: string}>
     */
    public const CTA_DEFAULTS = [
        'hero' => ['target' => 'galleries', 'style' => 'plein', 'label' => 'home.hero_cta'],
        'boutique' => ['target' => 'galleries', 'style' => 'plein', 'label' => 'home.shop_cta'],
        'atelier' => ['target' => 'about', 'style' => 'vide', 'label' => 'home.studio_cta'],
        'contact' => ['target' => 'contact', 'style' => 'vide', 'label' => 'home.contact_cta'],
    ];

    /** Tons du texte posé sur le fond du hero : sombre ou clair. */
    public const TONES = ['encre' => 'Texte sombre', 'papier' => 'Texte clair'];

    /** Nombre de cellules du triptyque et d'œuvres de la vitrine. */
    public const CELLS = 3;

    private const SHORT = 300;
    private const LONG = 2000;

    public static function isEditable(string $section): bool
    {
        return array_key_exists($section, self::SECTIONS);
    }

    public static function settingKey(string $section): string
    {
        return self::SECTIONS[$section];
    }

    public static function hasCta(string $section): bool
    {
        return array_key_exists($section, self::CTA_DEFAULTS);
    }

    /**
     * Réglages communs du CTA d'une section, défauts de la section compris.
     *
     * @param array<string, mixed> $document
     * @return array{
     *     target: string, category_id: int|null, url: string|null,
     *     style: string, align: string, enabled: bool
     * }
     */
    public static function ctaCommon(string $section, array $document): array
    {
        $defauts = self::CTA_DEFAULTS[$section] ?? ['target' => 'galleries', 'style' => 'plein'];
        $stocke = self::common($document)['cta'] ?? [];
        $stocke = is_array($stocke) ? $stocke : [];

        /** @var Cta $cta un libellé non vide garantit un CTA */
        $cta = Cta::fromStored(
            [
                'target' => $stocke['target'] ?? $defauts['target'],
                'style' => $stocke['style'] ?? $defauts['style'],
                'align' => $stocke['align'] ?? null,
                'category_id' => $stocke['category_id'] ?? null,
                'url' => $stocke['url'] ?? null,
            ],
            'x',
        );

        return [...$cta->toArray(), 'enabled' => ($stocke['enabled'] ?? true) !== false];
    }

    /**
     * Document du réglage mis à jour depuis les champs postés.
     *
     * @param array<string, mixed>       $document document actuel
     * @param array<string, string|null> $input    champs postés
     * @param int|null                   $mediaId  image jointe ou choisie (fond du hero, portrait)
     * @return array<string, mixed>
     */
    public static function apply(string $section, array $document, array $input, ?int $mediaId): array
    {
        foreach (Locale::cases() as $locale) {
            $langue = $locale->value;
            $partie = is_array($document[$langue] ?? null) ? $document[$langue] : [];

            foreach (self::TEXTS[$section] ?? [] as $champ => [, , $max]) {
                $valeur = self::clean($input[$champ . '_' . $langue] ?? null, $max);

                if ($champ === 'paragraphs') {
                    $partie[$champ] = self::paragraphs($valeur);
                } elseif ($valeur === '') {
                    unset($partie[$champ]);
                } else {
                    $partie[$champ] = $valeur;
                }
            }

            if ($section === 'triptyque') {
                $partie['cells'] = self::cells($input, $langue);
            }

            if ($section === 'vitrine' && $locale === Locale::reference()) {
                $partie['artwork_ids'] = self::showcase($input);
            }

            $document[$langue] = $partie;
        }

        if (self::hasCta($section)) {
            $document = self::applyCta($document, $input);
        }

        $common = self::common($document);

        if ($section === 'hero') {
            $retirer = ($input['fond_retirer'] ?? null) !== null;
            $common['background'] = [
                'media_id' => $retirer ? null : $mediaId,
                'color' => self::color($input['fond_couleur'] ?? null),
                'tone' => array_key_exists((string) ($input['fond_ton'] ?? ''), self::TONES)
                    ? (string) $input['fond_ton']
                    : 'encre',
            ];
        }

        if ($section === 'atelier') {
            $common['portrait_media_id'] = ($input['portrait_retirer'] ?? null) !== null ? null : $mediaId;
        }

        if ($common !== []) {
            $document['common'] = $common;
        }

        return $document;
    }

    /**
     * Valeurs à plat pour pré-remplir le formulaire.
     *
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    public static function toForm(string $section, array $document): array
    {
        $valeurs = [];

        foreach (Locale::cases() as $locale) {
            $langue = $locale->value;
            $partie = is_array($document[$langue] ?? null) ? $document[$langue] : [];

            foreach (array_keys(self::TEXTS[$section] ?? []) as $champ) {
                $valeur = $partie[$champ] ?? '';
                $valeurs[$champ . '_' . $langue] = is_array($valeur)
                    ? implode("\n\n", array_filter($valeur, 'is_string'))
                    : (is_string($valeur) ? $valeur : '');
            }

            if ($section === 'triptyque') {
                $cellules = is_array($partie['cells'] ?? null) ? array_values($partie['cells']) : [];
                for ($i = 1; $i <= self::CELLS; $i++) {
                    $cellule = is_array($cellules[$i - 1] ?? null) ? $cellules[$i - 1] : [];
                    $valeurs['cellule' . $i . '_titre_' . $langue] = self::str($cellule['title'] ?? null);
                    $valeurs['cellule' . $i . '_texte_' . $langue] = self::str($cellule['text'] ?? null);
                }
            }
        }

        $common = self::common($document);

        if (self::hasCta($section)) {
            $valeurs = [...$valeurs, ...self::ctaToForm($section, $document)];
        }

        if ($section === 'hero') {
            $fond = is_array($common['background'] ?? null) ? $common['background'] : [];
            $valeurs['fond'] = (string) (is_int($fond['media_id'] ?? null) ? $fond['media_id'] : '');
            $valeurs['fond_couleur'] = self::str($fond['color'] ?? null);
            $valeurs['fond_ton'] = self::str($fond['tone'] ?? null) ?: 'encre';
        }

        if ($section === 'atelier') {
            $portrait = $common['portrait_media_id'] ?? null;
            $valeurs['portrait'] = is_int($portrait) ? (string) $portrait : '';
        }

        if ($section === 'vitrine') {
            $reference = $document[Locale::reference()->value] ?? null;
            $partie = is_array($reference) ? $reference : [];
            $ids = is_array($partie['artwork_ids'] ?? null) ? array_values($partie['artwork_ids']) : [];
            for ($i = 1; $i <= self::CELLS; $i++) {
                $valeurs['vitrine_' . $i] = is_int($ids[$i - 1] ?? null) ? (string) $ids[$i - 1] : '';
            }
        }

        return $valeurs;
    }

    /**
     * CTA posté (libellé par langue dans `cta_{langue}`, réglages communs dans
     * `cta_*`) rangé dans le document. Sert aussi hors de l'accueil (fin d'actualité).
     *
     * @param array<string, mixed>       $document
     * @param array<string, string|null> $input
     * @return array<string, mixed>
     */
    public static function applyCta(array $document, array $input): array
    {
        foreach (Locale::cases() as $locale) {
            $partie = is_array($document[$locale->value] ?? null) ? $document[$locale->value] : [];
            $partie['cta'] = self::clean($input['cta_' . $locale->value] ?? null, self::SHORT);
            $document[$locale->value] = $partie;
        }

        /** @var Cta $cta un libellé non vide garantit un CTA */
        $cta = Cta::fromStored([
            'target' => $input['cta_target'] ?? null,
            'category_id' => $input['cta_category'] ?? null,
            'url' => $input['cta_url'] ?? null,
            'style' => $input['cta_style'] ?? null,
            'align' => $input['cta_align'] ?? null,
        ], 'x');

        $document['common'] = [
            ...self::common($document),
            'cta' => [...$cta->toArray(), 'enabled' => ($input['cta_affiche'] ?? null) !== null],
        ];

        return $document;
    }

    /**
     * Champs du CTA à plat, pour pré-remplir un formulaire.
     *
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    public static function ctaToForm(string $section, array $document): array
    {
        $cta = self::ctaCommon($section, $document);
        $valeurs = [];

        foreach (Locale::cases() as $locale) {
            $partie = is_array($document[$locale->value] ?? null) ? $document[$locale->value] : [];
            $valeurs['cta_' . $locale->value] = is_string($partie['cta'] ?? null) ? $partie['cta'] : '';
        }

        return [
            ...$valeurs,
            'cta_target' => $cta['target'],
            'cta_category' => (string) ($cta['category_id'] ?? ''),
            'cta_url' => (string) ($cta['url'] ?? ''),
            'cta_style' => $cta['style'],
            'cta_align' => $cta['align'],
            'cta_affiche' => $cta['enabled'] ? '1' : '',
        ];
    }

    /**
     * Couleur hexadécimale `#rrggbb`, ou null : elle finit dans une feuille de
     * style, aucune autre forme ne doit y parvenir.
     */
    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function common(array $document): array
    {
        return is_array($document['common'] ?? null) ? $document['common'] : [];
    }

    /**
     * Texte brut : caractères de contrôle retirés (sauf sauts de ligne), borné.
     */
    private static function clean(?string $value, int $max): string
    {
        $value = (string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
        $value = str_replace("\r\n", "\n", $value);

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @return list<string>
     */
    private static function paragraphs(string $value): array
    {
        $blocs = preg_split('/\n\s*\n/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $blocs), static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param array<string, string|null> $input
     * @return list<array{title: string, text: string}>
     */
    private static function cells(array $input, string $langue): array
    {
        $cellules = [];

        for ($i = 1; $i <= self::CELLS; $i++) {
            $titre = self::clean($input['cellule' . $i . '_titre_' . $langue] ?? null, self::SHORT);
            $texte = self::clean($input['cellule' . $i . '_texte_' . $langue] ?? null, self::LONG);

            if ($titre !== '' || $texte !== '') {
                $cellules[] = ['title' => $titre, 'text' => $texte];
            }
        }

        return $cellules;
    }

    /**
     * @param array<string, string|null> $input
     * @return list<int>
     */
    private static function showcase(array $input): array
    {
        $ids = [];

        for ($i = 1; $i <= self::CELLS; $i++) {
            $valeur = $input['vitrine_' . $i] ?? '';
            if (is_string($valeur) && ctype_digit($valeur) && (int) $valeur > 0) {
                $ids[] = (int) $valeur;
            }
        }

        return $ids;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
