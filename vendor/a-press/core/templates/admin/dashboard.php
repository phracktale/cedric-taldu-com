<?php

/**
 * @var array<string, mixed>              $data
 * @var APress\Core\Ui\UrlBuilder          $url
 * @var class-string<APress\Core\Ui\Html> $h
 */

declare(strict_types=1);

use APress\Core\Content\ContentType;

/** @var list<array{type: ContentType, total: int, enAttente: int}> $cartes */
$cartes = is_array($data['cartes'] ?? null) ? $data['cartes'] : [];
?>
<h1>Tableau de bord</h1>
<div class="cartes">
    <?php foreach ($cartes as $carte) : ?>
    <a class="carte postbox" href="<?= $h::attr($url->admin('contenu/' . $carte['type']->name)) ?>">
        <strong><?= $h::e($carte['type']->label) ?></strong>
        <?php if (!$carte['type']->single) : ?>
        <span><?= $h::e($carte['total']) ?> entrée(s)<?= $h::e($carte['enAttente'] > 0 ? ' — ' . $carte['enAttente'] . ' à publier' : '') ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
