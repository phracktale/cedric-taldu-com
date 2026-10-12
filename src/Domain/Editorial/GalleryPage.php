<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Texte explicatif de la page Galerie (compte rendu client du 2026-10-12 :
 * « la page Galerie doit pouvoir contenir un texte explicatif avec Titre et
 * paragraphe »). Réglage `gallery.page` : un titre et un texte par langue.
 *
 * Le texte est du TEXTE SIMPLE, pas du HTML : une ligne vide sépare deux
 * paragraphes, un simple retour à la ligne reste un retour à la ligne. Rien
 * n'est interprété ; le gabarit échappe chaque ligne à l'affichage.
 */
final class GalleryPage
{
    public const SETTING = 'gallery.page';

    public const HEADING_MAX = 160;

    public const TEXT_MAX = 6000;

    /**
     * @param array{fr: string, en: string} $heading
     * @param array{fr: string, en: string} $text
     */
    private function __construct(
        private readonly array $heading,
        private readonly array $text,
    ) {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $champ = static function (string $langue, string $cle, int $max) use ($stored): string {
            $valeur = is_array($stored[$langue] ?? null) ? ($stored[$langue][$cle] ?? null) : null;

            if (!is_string($valeur)) {
                return '';
            }

            // Fins de ligne unifiées : un formulaire envoie des « \r\n ».
            return trim(mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $valeur)), 0, $max));
        };

        return new self(
            ['fr' => $champ('fr', 'heading', self::HEADING_MAX), 'en' => $champ('en', 'heading', self::HEADING_MAX)],
            ['fr' => $champ('fr', 'text', self::TEXT_MAX), 'en' => $champ('en', 'text', self::TEXT_MAX)],
        );
    }

    /**
     * Saisie du back-office : `titre_fr`, `texte_fr`, `titre_en`, `texte_en`.
     *
     * @param array<string, mixed> $input
     */
    public static function fromForm(array $input): self
    {
        $valeur = static fn (string $cle): string => is_string($input[$cle] ?? null) ? $input[$cle] : '';

        return self::fromStored([
            'fr' => ['heading' => $valeur('titre_fr'), 'text' => $valeur('texte_fr')],
            'en' => ['heading' => $valeur('titre_en'), 'text' => $valeur('texte_en')],
        ]);
    }

    /** Un titre ou un texte existe-t-il pour cette langue (repli compris) ? */
    public function hasContent(Locale $locale): bool
    {
        $langue = $this->source($locale);

        return $this->heading[$langue] !== '' || $this->text[$langue] !== '';
    }

    public function heading(Locale $locale): ?string
    {
        $titre = $this->heading[$this->source($locale)];

        return $titre === '' ? null : $titre;
    }

    /**
     * Paragraphes du texte, chacun découpé en lignes.
     *
     * @return list<list<string>>
     */
    public function paragraphs(Locale $locale): array
    {
        $texte = $this->text[$this->source($locale)];

        if ($texte === '') {
            return [];
        }

        $paragraphes = [];

        foreach (preg_split('/\n[ \t]*\n/', $texte) ?: [] as $bloc) {
            $lignes = array_values(array_filter(
                array_map('trim', explode("\n", $bloc)),
                static fn (string $ligne): bool => $ligne !== '',
            ));

            if ($lignes !== []) {
                $paragraphes[] = $lignes;
            }
        }

        return $paragraphes;
    }

    /**
     * @return array{fr: array{heading: string, text: string}, en: array{heading: string, text: string}}
     */
    public function toArray(): array
    {
        return [
            'fr' => ['heading' => $this->heading['fr'], 'text' => $this->text['fr']],
            'en' => ['heading' => $this->heading['en'], 'text' => $this->text['en']],
        ];
    }

    /**
     * Langue dont viennent titre ET texte : celle demandée dès qu'elle a l'un
     * des deux, le français sinon. Le repli se fait en bloc, pour ne jamais
     * coiffer un texte anglais d'un titre français.
     *
     * @return 'fr'|'en'
     */
    private function source(Locale $locale): string
    {
        $langue = $locale === Locale::En ? 'en' : 'fr';

        return $this->heading[$langue] !== '' || $this->text[$langue] !== '' ? $langue : 'fr';
    }
}
