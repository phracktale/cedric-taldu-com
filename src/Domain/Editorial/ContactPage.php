<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Contenu de la page contact (retour client du 2026-09-29, point 13) : titre
 * et introduction par langue ; adresse, téléphone et e-mail de l'artiste,
 * affichés en vis-à-vis du formulaire. Réglage `contact.page`.
 */
final class ContactPage
{
    public const SETTING = 'contact.page';

    /**
     * @param array{fr: string, en: string} $title  vide : le titre d'interface « Contact »
     * @param array{fr: string, en: string} $intro
     */
    private function __construct(
        private readonly array $title,
        private readonly array $intro,
        public readonly string $address,
        public readonly string $phone,
        public readonly string $email,
    ) {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $texte = static fn (mixed $v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $parLangue = static fn (string $cle, int $max): array => [
            'fr' => $texte(is_array($stored['fr'] ?? null) ? ($stored['fr'][$cle] ?? null) : null, $max),
            'en' => $texte(is_array($stored['en'] ?? null) ? ($stored['en'][$cle] ?? null) : null, $max),
        ];
        $commun = is_array($stored['common'] ?? null) ? $stored['common'] : [];
        $email = $texte($commun['email'] ?? null, 190);

        return new self(
            $parLangue('title', 120),
            $parLangue('intro', 2000),
            $texte($commun['address'] ?? null, 400),
            $texte($commun['phone'] ?? null, 40),
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ? '' : $email,
        );
    }

    /**
     * @param array<string, string|null> $input
     * @return array{0: self, 1: list<string>}
     */
    public static function fromForm(array $input): array
    {
        $valeur = static fn (string $cle): string => trim((string) ($input[$cle] ?? ''));
        $erreurs = [];

        $email = $valeur('email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erreurs[] = 'Adresse e-mail invalide.';
        }

        $telephone = $valeur('telephone');
        if ($telephone !== '' && preg_match('/^\+?[0-9 .()-]{6,30}$/D', $telephone) !== 1) {
            $erreurs[] = 'Numéro de téléphone invalide (chiffres, espaces, points, tirets, + en tête).';
        }

        $page = self::fromStored([
            'fr' => ['title' => $valeur('title_fr'), 'intro' => $valeur('intro_fr')],
            'en' => ['title' => $valeur('title_en'), 'intro' => $valeur('intro_en')],
            'common' => ['address' => str_replace("\r\n", "\n", $valeur('adresse')), 'phone' => $telephone, 'email' => $email],
        ]);

        return [$page, $erreurs];
    }

    public function title(Locale $locale): ?string
    {
        return self::localized($this->title, $locale);
    }

    public function intro(Locale $locale): ?string
    {
        return self::localized($this->intro, $locale);
    }

    public function hasCoordinates(): bool
    {
        return $this->address !== '' || $this->phone !== '' || $this->email !== '';
    }

    /**
     * Lignes de l'adresse, pour un rendu ligne par ligne échappé.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->address)), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Lien tel: au format international ; un numéro français « 06… » devient
     * « +336… ».
     */
    public function phoneHref(): string
    {
        $chiffres = (string) preg_replace('/[^0-9+]/', '', $this->phone);

        if (str_starts_with($chiffres, '0') && strlen($chiffres) === 10) {
            $chiffres = '+33' . substr($chiffres, 1);
        }

        return 'tel:' . $chiffres;
    }

    /**
     * @return array{fr: array{title: string, intro: string}, en: array{title: string, intro: string}, common: array{address: string, phone: string, email: string}}
     */
    public function toArray(): array
    {
        return [
            'fr' => ['title' => $this->title['fr'], 'intro' => $this->intro['fr']],
            'en' => ['title' => $this->title['en'], 'intro' => $this->intro['en']],
            'common' => ['address' => $this->address, 'phone' => $this->phone, 'email' => $this->email],
        ];
    }

    /**
     * @param array{fr: string, en: string} $valeurs
     */
    private static function localized(array $valeurs, Locale $locale): ?string
    {
        $texte = $valeurs[$locale->value] !== '' ? $valeurs[$locale->value] : $valeurs['fr'];

        return $texte === '' ? null : $texte;
    }
}
