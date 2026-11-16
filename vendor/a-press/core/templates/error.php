<?php

/**
 * Page d'erreur du cœur (ErrorResponder). Aucune trace, aucun chemin serveur :
 * un identifiant de corrélation pour retrouver l'incident dans le journal.
 *
 * @var array<string, mixed>          $data
 * @var APress\Core\Ui\UrlBuilder      $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

$statut = is_int($data['statut'] ?? null) ? $data['statut'] : 500;
$titre = is_string($data['titre'] ?? null) ? $data['titre'] : 'Erreur';
$message = is_string($data['message'] ?? null) ? $data['message'] : '';
$correlation = is_string($data['correlationId'] ?? null) ? $data['correlationId'] : null;
$detail = is_string($data['detail'] ?? null) ? $data['detail'] : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $h::e($statut . ' — ' . $titre) ?></title>
<link rel="stylesheet" href="<?= $h::attr($url->asset('admin.css')) ?>">
</head>
<body class="wp-admin wp-connexion">
<main class="erreur-page">
    <h1><?= $h::e($titre) ?></h1>
    <p><?= $h::e($message) ?></p>
    <?php if ($correlation !== null) : ?>
    <p class="aide">Référence de l’incident : <code><?= $h::e($correlation) ?></code></p>
    <?php endif; ?>
    <?php if ($detail !== null) : ?>
    <pre><?= $h::e($detail) ?></pre>
    <?php endif; ?>
</main>
</body>
</html>
