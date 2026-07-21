<?php

/**
 * Page d'erreur, tous statuts.
 *
 * 06-securite §10 : hors developpement, elle affiche un identifiant de
 * correlation et rien d'autre. Aucun message d'exception, aucune trace, aucun
 * chemin serveur, aucune requete SQL n'atteint le navigateur.
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 */

declare(strict_types=1);

$locale = is_string($data['locale'] ?? null) ? $data['locale'] : 'fr';
$statut = is_int($data['statut'] ?? null) ? $data['statut'] : 500;
$titre = is_string($data['titre'] ?? null) ? $data['titre'] : 'Erreur';
$message = is_string($data['message'] ?? null) ? $data['message'] : '';
$correlationId = is_string($data['correlationId'] ?? null) ? $data['correlationId'] : null;
$detail = is_string($data['detail'] ?? null) ? $data['detail'] : null;

?>
<!DOCTYPE html>
<html lang="<?= attr($locale) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= e($titre) ?> — Cédric Taldu</title>
</head>
<body>
<main>
<p><?= e($statut) ?></p>
<h1><?= e($titre) ?></h1>
<p><?= e($message) ?></p>
<?php if ($correlationId !== null) : ?>
<p>Référence de l’incident : <code><?= e($correlationId) ?></code></p>
<?php endif; ?>
<?php if ($detail !== null) : ?>
<pre><?= e($detail) ?></pre>
<?php endif; ?>
<p><a href="<?= attr($url->route('home', ['locale' => $locale])) ?>">Retour à l’accueil</a></p>
</main>
</body>
</html>
