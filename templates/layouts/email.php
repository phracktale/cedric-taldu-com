<?php

/**
 * Mise en page des courriels.
 *
 * Styles EN LIGNE : les clients de messagerie ignorent les feuilles externes,
 * et beaucoup suppriment meme la balise <style>. Aucune image distante non
 * plus — elle trahirait l'ouverture du message (06-securite §9).
 *
 * richText() est reserve au HTML passe par l'assainisseur (src/CLAUDE.md).
 * $content est le rendu d'un autre gabarit : Core\View l'autorise tel quel.
 *
 * @var string $content
 */



declare(strict_types=1);

/** @var App\Domain\Locale $locale */
$locale = $data['locale'];
/** @var array<string, string> $strings */
$strings = $data['strings'];
// Titre du document : les courriels de commande passent `order`, les autres
// (contact) fournissent `docTitle`. Le repli garde les commandes intactes.
$docTitle = is_string($data['docTitle'] ?? null) ? $data['docTitle'] : ($strings['order'] ?? '');
// Identité du site (Paramètres › Global), partagée par View::share.
$site = ($data['site'] ?? null) instanceof App\Domain\Editorial\SiteIdentity
    ? $data['site']
    : App\Domain\Editorial\SiteIdentity::fromStored([]);
?>
<!doctype html>
<html lang="<?= attr($locale->value) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docTitle) ?></title>
</head>
<body style="margin:0;padding:24px;background:#f6f5f2;font-family:Georgia,'Times New Roman',serif;color:#1c1a17;">
<div style="max-width:600px;margin:0 auto;background:#fffdfa;padding:32px;border:1px solid #e4e0d8;">
<?= $content ?>
<p style="margin-top:32px;padding-top:16px;border-top:1px solid #e4e0d8;font-size:13px;color:#6b655c;">
<?= e($site->name) ?><?php if ($site->city !== '') : ?> — <?= e($site->city) ?><?php endif; ?>
</p>
</div>
</body>
</html>
