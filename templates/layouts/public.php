<?php

/**
 * Gabarit public minimal du lot 0.
 *
 * Le design system des maquettes (palette, Marcellus/Jost auto-hebergees,
 * en-tete collante, pied de page) est extrait au lot 1 : ici, seule la
 * structure indispensable au fonctionnement de la chaine est posee.
 *
 * @var array<string, mixed>                 $data
 * @var App\Service\I18n\UrlGenerator        $url
 */

declare(strict_types=1);

$locale = is_string($data['locale'] ?? null) ? $data['locale'] : 'fr';
$titre = is_string($data['titre'] ?? null) ? $data['titre'] : '';
$contenu = is_string($data['contenu'] ?? null) ? $data['contenu'] : '';

?>
<!DOCTYPE html>
<html lang="<?= attr($locale) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($titre) ?> — Cédric Taldu</title>
</head>
<body data-base="<?= attr($url->route('home', ['locale' => $locale])) ?>">
<main>
<?= $contenu ?>
</main>
</body>
</html>
