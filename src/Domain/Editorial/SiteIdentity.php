<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Locale;

/**
 * Identité du site (retours du 2026-09-25, Paramètres › Global) : ce qui était
 * écrit en dur pour Cédric Taldu, pour que le site serve à d'autres artistes.
 * Nom, accroche et métier par langue, ville, première année du ©, titre de
 * l'accueil pour les moteurs, liens vers les réseaux. Réglage `site.identity`.
 *
 * Sans réglage, les valeurs historiques du site : rien ne change à
 * l'installation.
 */
final class SiteIdentity
{
    public const SETTING = 'site.identity';

    public const MAX_SOCIALS = 4;

    private const DEFAULTS = [
        'name' => 'Cédric Taldu',
        'tagline' => ['fr' => 'artiste plasticien — Amiens', 'en' => 'visual artist — Amiens, France'],
        'role' => ['fr' => 'Artiste plasticien, Amiens, Hauts-de-France', 'en' => 'Visual artist, Amiens, France'],
        'city' => 'Amiens',
        'since' => 2025,
        'home_title' => [
            'fr' => 'Cédric Taldu | Artiste peintre et dessinateur à Amiens',
            'en' => 'Cédric Taldu | Visual artist in Amiens, France',
        ],
    ];

    /** Libellé d'un réseau connu, d'après son domaine. */
    private const NETWORKS = [
        'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'youtube.com' => 'YouTube',
        'pinterest.com' => 'Pinterest',
        'linkedin.com' => 'LinkedIn',
        'tiktok.com' => 'TikTok',
        'x.com' => 'X',
        'bsky.app' => 'Bluesky',
    ];

    /**
     * @param array{fr: string, en: string} $tagline
     * @param array{fr: string, en: string} $role
     * @param array{fr: string, en: string} $homeTitle vide : nom et métier
     * @param list<string>                  $socials
     */
    private function __construct(
        public readonly string $name,
        private readonly array $tagline,
        private readonly array $role,
        public readonly string $city,
        public readonly int $since,
        private readonly array $homeTitle,
        public readonly array $socials,
    ) {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        if ($stored === []) {
            return new self(
                self::DEFAULTS['name'],
                self::DEFAULTS['tagline'],
                self::DEFAULTS['role'],
                self::DEFAULTS['city'],
                self::DEFAULTS['since'],
                self::DEFAULTS['home_title'],
                [],
            );
        }

        $texte = static fn (mixed $v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $parLangue = static fn (mixed $v, int $max): array => [
            'fr' => $texte(is_array($v) ? ($v['fr'] ?? '') : '', $max),
            'en' => $texte(is_array($v) ? ($v['en'] ?? '') : '', $max),
        ];
        $nom = $texte($stored['name'] ?? null, 80);
        $depuis = $stored['since'] ?? null;

        return new self(
            $nom === '' ? self::DEFAULTS['name'] : $nom,
            $parLangue($stored['tagline'] ?? null, 120),
            $parLangue($stored['role'] ?? null, 160),
            $texte($stored['city'] ?? null, 80),
            is_int($depuis) && $depuis >= 1900 && $depuis <= 2100 ? $depuis : self::DEFAULTS['since'],
            $parLangue($stored['home_title'] ?? null, 180),
            array_values(array_filter(
                is_array($stored['socials'] ?? null) ? array_slice($stored['socials'], 0, self::MAX_SOCIALS) : [],
                static fn (mixed $u): bool => is_string($u) && self::isSafeUrl($u),
            )),
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

        $nom = mb_substr($valeur('nom'), 0, 80);
        if ($nom === '') {
            $erreurs[] = 'Nom : obligatoire.';
        }

        $depuis = $valeur('depuis');
        $annee = (int) date('Y');
        if (preg_match('/^[0-9]{4}$/D', $depuis) !== 1 || (int) $depuis < 1900 || (int) $depuis > $annee) {
            $erreurs[] = 'Première année : entre 1900 et l’année en cours.';
        }

        $reseaux = [];
        for ($n = 0; $n < self::MAX_SOCIALS; $n++) {
            $url = $valeur('reseau_' . $n);
            if ($url === '') {
                continue;
            }
            if (!self::isSafeUrl($url)) {
                $erreurs[] = 'Réseau « ' . $url . ' » : une adresse en https://.';
                continue;
            }
            $reseaux[] = $url;
        }

        $site = self::fromStored([
            'name' => $nom === '' ? self::DEFAULTS['name'] : $nom,
            'tagline' => ['fr' => $valeur('accroche_fr'), 'en' => $valeur('accroche_en')],
            'role' => ['fr' => $valeur('metier_fr'), 'en' => $valeur('metier_en')],
            'city' => $valeur('ville'),
            'since' => (int) $depuis,
            'home_title' => ['fr' => $valeur('titre_accueil_fr'), 'en' => $valeur('titre_accueil_en')],
            'socials' => $reseaux,
        ]);

        return [$site, $erreurs];
    }

    public function tagline(Locale $locale): string
    {
        return self::localized($this->tagline, $locale);
    }

    public function role(Locale $locale): string
    {
        return self::localized($this->role, $locale);
    }

    /**
     * Métier seul, pour les données structurées : le métier français avant sa
     * première virgule (« Artiste plasticien, Amiens » → « Artiste plasticien »).
     */
    public function jobTitle(): string
    {
        return trim(explode(',', $this->role(Locale::Fr))[0]);
    }

    /**
     * Titre de l'accueil pour les moteurs ; à défaut, nom et métier.
     */
    public function homeTitle(Locale $locale): string
    {
        $titre = self::localized($this->homeTitle, $locale);
        if ($titre !== '') {
            return $titre;
        }

        $metier = $this->role($locale);

        return $metier === '' ? $this->name : $this->name . ' | ' . $metier;
    }

    /**
     * @return list<array{url: string, label: string}>
     */
    public function socialLinks(): array
    {
        return array_map(static function (string $url): array {
            $hote = (string) parse_url($url, PHP_URL_HOST);
            $hote = (string) preg_replace('/^www\./', '', $hote);

            return ['url' => $url, 'label' => self::NETWORKS[$hote] ?? $hote];
        }, $this->socials);
    }

    /**
     * @return array{name: string, tagline: array{fr: string, en: string}, role: array{fr: string, en: string}, city: string, since: int, home_title: array{fr: string, en: string}, socials: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tagline' => $this->tagline,
            'role' => $this->role,
            'city' => $this->city,
            'since' => $this->since,
            'home_title' => $this->homeTitle,
            'socials' => $this->socials,
        ];
    }

    /**
     * @param array{fr: string, en: string} $valeurs
     */
    private static function localized(array $valeurs, Locale $locale): string
    {
        $texte = $valeurs[$locale->value] ?? '';

        return $texte !== '' ? $texte : $valeurs['fr'];
    }

    private static function isSafeUrl(string $url): bool
    {
        return mb_strlen($url) <= 300 && preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/[^\s"<>]*)?$#iD', $url) === 1;
    }
}
