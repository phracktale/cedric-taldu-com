<?php

/**
 * Jeu de données de démonstration.
 *
 * Usage :
 *   php bin/seed.php --demo          rubriques, séries, œuvres et réglages
 *   php bin/seed.php --demo --force  efface le catalogue existant d'abord
 *
 * Reprend les œuvres et les textes des maquettes de maquette/. Les visuels sont
 * des images de remplacement engendrées à la trame du site, aux dimensions
 * réelles des œuvres : la préproduction a la bonne forme sans prétendre montrer
 * un travail qui n'est pas encore là.
 *
 * REFUSE DE S'EXÉCUTER EN PRODUCTION (09-environnements §7 : aucune donnée de
 * démonstration en prod, aucune donnée client en preprod).
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Domain\Catalog\ArtworkStatus;
use App\Service\Media\PlaceholderGenerator;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var array<string, string> $systemEnvironment */
$systemEnvironment = getenv();
$env = Env::load($root . '/.env', $systemEnvironment);
$config = Config::fromEnv($env);

$arguments = array_slice($argv ?? [], 1);

if (!in_array('--demo', $arguments, true)) {
    fwrite(STDERR, "Usage : php bin/seed.php --demo [--force]\n");
    exit(1);
}

if ($config->isProduction()) {
    fwrite(STDERR, "Refus : le jeu de démonstration ne s'exécute jamais en production.\n");
    exit(1);
}

$pdo = Database::fromEnv($env, migration: true)->connect();
$images = new PlaceholderGenerator($root . '/public/media');

$compter = static function (string $table) use ($pdo): int {
    $statement = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

    return $statement === false ? 0 : (int) $statement->fetchColumn();
};

if ($compter('categories') > 0) {
    if (!in_array('--force', $arguments, true)) {
        fwrite(STDERR, "Le catalogue n'est pas vide. Relancez avec --force pour l'écraser.\n");
        exit(1);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (
        ['artwork_media', 'artwork_translations', 'artworks', 'series_translations', 'series',
              'category_translations', 'categories', 'media_translations', 'media'] as $table
    ) {
        $pdo->exec('TRUNCATE TABLE `' . $table . '`');
    }
    $pdo->exec("DELETE FROM settings WHERE `key` LIKE 'home.%'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    fwrite(STDOUT, "Catalogue existant effacé.\n");
}

// ---------------------------------------------------------------- fonctions

$inserer = static function (string $sql, array $valeurs) use ($pdo): int {
    $pdo->prepare($sql)->execute($valeurs);

    return (int) $pdo->lastInsertId();
};

/**
 * Crée un média et engendre tous ses dérivés.
 */
$creerMedia = static function (string $basename, int $largeur, int $hauteur, string $alt, ?string $altEn)
 use ($inserer, $images, $pdo): int {
    $images->generate($basename, $largeur, $hauteur);

    $id = $inserer(
        'INSERT INTO media (storage_path, public_basename, mime, width, height, bytes, checksum, created_at)
         VALUES (:path, :base, :mime, :w, :h, :bytes, :sum, NOW())',
        [
            'path' => 'storage/uploads/demo/' . $basename . '.jpg',
            'base' => $basename,
            'mime' => 'image/jpeg',
            'w' => $largeur,
            'h' => $hauteur,
            'bytes' => 0,
            'sum' => hash('sha256', $basename),
        ],
    );

    $pdo->prepare('INSERT INTO media_translations (media_id, locale, alt) VALUES (:id, :l, :a)')
        ->execute(['id' => $id, 'l' => 'fr', 'a' => $alt]);

    if ($altEn !== null) {
        $pdo->prepare('INSERT INTO media_translations (media_id, locale, alt) VALUES (:id, :l, :a)')
            ->execute(['id' => $id, 'l' => 'en', 'a' => $altEn]);
    }

    return $id;
};

$reglage = static function (string $cle, array $valeur) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, NOW())
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
    )->execute(['k' => $cle, 'v' => json_encode($valeur, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
};

// ------------------------------------------------------------- rubriques

fwrite(STDOUT, "Rubriques…\n");

$encres = $inserer(
    'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (0, 1, NOW(), NOW())',
    [],
);
$pdo->prepare(
    'INSERT INTO category_translations (category_id, locale, slug, eyebrow, title, description)
     VALUES (:id, :l, :s, :e, :t, :d)'
)->execute([
    'id' => $encres, 'l' => 'fr', 's' => 'encres', 'e' => 'Galerie',
    't' => 'Dessin à l’encre de Chine, point par point',
    'd' => '<p>Le dessin avance lentement. À l’encre de Chine, chaque point engage la surface. '
        . 'L’image se construit par reprises et densités. Des blancs sont laissés ouverts.</p>',
]);
$pdo->prepare(
    'INSERT INTO category_translations (category_id, locale, slug, eyebrow, title, description)
     VALUES (:id, :l, :s, :e, :t, :d)'
)->execute([
    'id' => $encres, 'l' => 'en', 's' => 'inks', 'e' => 'Gallery',
    't' => 'India ink drawing, dot by dot',
    'd' => '<p>The drawing advances slowly. In India ink, every dot commits the surface.</p>',
]);

$peintures = $inserer(
    'INSERT INTO categories (position, is_published, created_at, updated_at) VALUES (10, 1, NOW(), NOW())',
    [],
);
$pdo->prepare(
    'INSERT INTO category_translations (category_id, locale, slug, eyebrow, title, description)
     VALUES (:id, :l, :s, :e, :t, :d)'
)->execute([
    'id' => $peintures, 'l' => 'fr', 's' => 'peintures', 'e' => 'Galerie',
    't' => 'Huile sur toile, la surface par accumulation',
    'd' => '<p>À l’huile sur toile, la surface se construit par accumulation. '
        . 'Elle prend l’épaisseur d’une peau et porte ses propres cicatrices.</p>',
]);

// ---------------------------------------------------------------- séries

fwrite(STDOUT, "Séries…\n");

$series = [];

foreach (
    [['piliers', 'Piliers', 'pillars', 'Pillars', 0],
          ['fondations', 'Fondations', 'foundations', 'Foundations', 10],
          ['figures', 'Figures', 'figures', 'Figures', 20]] as [$slug, $titre, $slugEn, $titreEn, $position]
) {
    $id = $inserer(
        'INSERT INTO series (category_id, position, is_published, created_at, updated_at)
         VALUES (:c, :p, 1, NOW(), NOW())',
        ['c' => $encres, 'p' => $position],
    );

    $pdo->prepare('INSERT INTO series_translations (series_id, locale, slug, title) VALUES (:i, :l, :s, :t)')
        ->execute(['i' => $id, 'l' => 'fr', 's' => $slug, 't' => $titre]);
    $pdo->prepare('INSERT INTO series_translations (series_id, locale, slug, title) VALUES (:i, :l, :s, :t)')
        ->execute(['i' => $id, 'l' => 'en', 's' => $slugEn, 't' => $titreEn]);

    $series[$slug] = $id;
}

// ---------------------------------------------------------------- œuvres

fwrite(STDOUT, "Œuvres et visuels de remplacement…\n");

/**
 * Reprises des légendes des maquettes : titre, année, dimensions en millimètres,
 * série, statut, prix en centimes.
 */
$oeuvres = [
    ['articulation', 'Articulation', 2026, 100, 165, null, ArtworkStatus::Available, 45000],
    ['autoportrait-au-baron-samedi', 'Autoportrait au Baron Samedi', 2026, 240, 320, 'figures', ArtworkStatus::Available, 78000],
    ['auto-portrait-au-pendu', 'Auto-portrait au Pendu, Arcane Majeure', 2024, 160, 230, 'figures', ArtworkStatus::Sold, 62000],
    ['pilier-i', 'Pilier I', 2025, 140, 210, 'piliers', ArtworkStatus::Available, 39000],
    ['pilier-ii', 'Pilier II', 2024, 140, 210, 'piliers', ArtworkStatus::Sold, 39000],
    ['fondation-x', 'Fondation X', 2026, 50, 130, 'fondations', ArtworkStatus::Available, 28000],
    ['fondations', 'Fondations', 2025, 230, 160, 'fondations', ArtworkStatus::Available, 52000],
    ['fondation-ii', 'Fondation II', 2025, 230, 160, 'fondations', ArtworkStatus::NotForSale, null],
];

$identifiants = [];
$position = 0;

foreach ($oeuvres as [$slug, $titre, $annee, $largeurMm, $hauteurMm, $serie, $statut, $prix]) {
    // Le visuel garde le rapport d'aspect réel de l'œuvre, en cadrant le CÔTÉ
    // LONG à 2400 px. Cadrer la largeur donnerait, pour une œuvre haute et
    // étroite comme « Fondation X » (5 × 13 cm), une image de 2400 × 6240 —
    // plusieurs dizaines de mégaoctets en mémoire, pour rien.
    $cote = 2400;
    $largeurPx = $hauteurMm > $largeurMm ? (int) round($cote * $largeurMm / $hauteurMm) : $cote;
    $hauteurPx = $hauteurMm > $largeurMm ? $cote : (int) round($cote * $hauteurMm / $largeurMm);

    $media = $creerMedia(
        $slug,
        $largeurPx,
        $hauteurPx,
        $titre . ', encre de Chine sur papier',
        $titre . ', India ink on paper',
    );

    $id = $inserer(
        'INSERT INTO artworks
            (category_id, series_id, reference, year, technique, width_mm, height_mm, is_signed,
             price_cents, status, weight_grams, primary_media_id, position, is_published, published_at,
             created_at, updated_at)
         VALUES
            (:c, :s, :r, :y, :tech, :w, :h, 1, :price, :status, 120, :media, :pos, 1, :pub, NOW(), NOW())',
        [
            'c' => $encres,
            's' => $serie === null ? null : $series[$serie],
            'r' => 'CT-ENC-' . str_pad((string) (++$position), 3, '0', STR_PAD_LEFT),
            'y' => $annee,
            'tech' => 'Encre de Chine sur papier',
            'w' => $largeurMm,
            'h' => $hauteurMm,
            'price' => $prix,
            'status' => $statut->value,
            'media' => $media,
            'pos' => $position * 10,
            'pub' => sprintf('2026-%02d-01 09:00:00', min(12, $position)),
        ],
    );

    $pdo->prepare(
        'INSERT INTO artwork_translations (artwork_id, locale, slug, eyebrow, title, description, detail)
         VALUES (:i, :l, :s, :e, :t, :d, :det)'
    )->execute([
        'i' => $id, 'l' => 'fr', 's' => $slug,
        'e' => 'Œuvre originale · Pièce unique',
        't' => $titre,
        'd' => '<p>Le dessin avance point par point, à partir de structures anatomiques réelles '
            . 'dont les relations sont modifiées pour faire apparaître d’autres organisations possibles du corps.</p>',
        'det' => '<p>Pièce unique, réalisée à la main dans mon atelier à Amiens. '
            . 'Certificat d’authenticité signé.</p>',
    ]);

    $pdo->prepare('INSERT INTO artwork_media (artwork_id, media_id, role, position) VALUES (:a, :m, :r, 0)')
        ->execute(['a' => $id, 'm' => $media, 'r' => 'main']);

    $identifiants[$slug] = $id;
}

// -------------------------------------------------------------- réglages

fwrite(STDOUT, "Réglages de l'accueil…\n");

$reglage('home.hero', [
    'fr' => [
        'eyebrow' => 'Encre de Chine · Huile sur toile',
        'title' => 'Cédric Taldu, artiste peintre et dessinateur à Amiens',
        'baseline' => 'Une recherche consacrée au corps vécu. Le dessin avance point par point, '
            . 'à partir de structures anatomiques réelles dont les relations sont modifiées.',
        'cta' => 'Voir les œuvres',
    ],
    'en' => [
        'eyebrow' => 'India ink · Oil on canvas',
        'title' => 'Cédric Taldu, painter and draughtsman in Amiens, France',
        'baseline' => 'A body of research devoted to the lived body.',
        'cta' => 'View the works',
    ],
]);

$reglage('home.showcase', [
    'fr' => ['artwork_ids' => [
        $identifiants['pilier-i'],
        $identifiants['autoportrait-au-baron-samedi'],
        $identifiants['articulation'],
    ]],
]);

$reglage('home.triptych', [
    'fr' => [
        'eyebrow' => 'Territoire de recherche',
        'title' => 'Corps visible, corps divisible, corps vécu',
        'intro' => 'Mon travail interroge ce que devient le corps contemporain lorsqu’il doit '
            . 'composer avec les images qui le normalisent.',
        'cells' => [
            ['title' => 'Corps visible', 'text' => 'Celui que l’époque expose.'],
            ['title' => 'Corps divisible', 'text' => 'Celui que le dessin fait advenir.'],
            ['title' => 'Corps vécu', 'text' => 'Celui qui se construit dans cette opération.'],
        ],
    ],
]);

$reglage('home.shop', [
    'fr' => [
        'eyebrow' => 'Boutique',
        'title' => 'Acquérir une œuvre originale',
        'text' => 'Chaque œuvre est une pièce unique, réalisée à la main dans mon atelier à Amiens. '
            . 'Certificat d’authenticité signé, remise en main propre à Amiens ou expédition soignée.',
    ],
]);

$reglage('home.studio', [
    'fr' => [
        'eyebrow' => 'L’artiste',
        'title' => 'Né au Havre, installé à Amiens',
        'lead' => 'Formé à la faculté d’arts plastiques du Logis du Roy à Amiens, je développe depuis '
            . 'le milieu des années 1990 une recherche consacrée au corps vécu.',
        'paragraphs' => [
            'Après une interruption de ma pratique, j’ai repris le dessin en 2023. Ce nouveau corpus '
            . 's’ouvre vers des compositions où les relations entre les formes priment sur leur accumulation.',
            'Mes œuvres ont été présentées à Art Capital au Grand Palais à Paris, avec l’association '
            . 'DF Art Project, et au 45e Salon de Lives.',
        ],
    ],
]);

$reglage('home.news', [
    'fr' => [
        'title' => 'Expositions et travail en cours',
        'items' => [
            ['date' => '2026-10', 'label' => 'Oct. 2026', 'title' => 'Salon DF Art Project',
             'place' => 'Verrière du parc André Citroën, Paris'],
            ['date' => '2026', 'label' => '2026',
             'title' => 'Exposition personnelle — œuvres anciennes et nouveau corpus', 'place' => 'Amiens'],
        ],
    ],
]);

$reglage('home.contact', [
    'fr' => [
        'eyebrow' => 'Contact',
        'title' => 'Une œuvre, une exposition, une visite d’atelier',
        'text' => 'L’atelier se visite sur rendez-vous, à Amiens. Pour toute question sur une œuvre, '
            . 'un format ou une acquisition, écrivez-moi. Je réponds personnellement.',
    ],
]);

fwrite(STDOUT, sprintf(
    "\nTerminé : %d rubriques, %d séries, %d œuvres, %d médias avec leurs dérivés.\n",
    2,
    count($series),
    count($identifiants),
    count($identifiants),
));
