<?php

/**
 * Mediatheque.
 *
 * Le champ de televersement est un `<input type="file" multiple>` ordinaire :
 * le glisser-deposer de 04-back-office §7 est une amelioration du module JS, et
 * la page fonctionne entierement sans lui (§12).
 *
 * Les vignettes pointent le derive de 320 px, produit pour toutes les images
 * quelle que soit leur taille (Media::availableWidths()).
 *
 * @var array<string, mixed>          $data
 * @var App\Service\I18n\UrlGenerator $url
 * @var callable                      $partial
 */

declare(strict_types=1);

use App\Service\Media\UploadValidator;

$base = is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
$jeton = is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : '';

// Limite lue dans le validateur : le texte ne peut plus rester en retard sur
// elle (« 25 Mo » était resté affiché après le passage à 150 Mo).
$limiteMo = intdiv(UploadValidator::MAX_BYTES, 1024 * 1024);

/** @var list<array{id: int, code: string, titre: string, depose: bool}> $documents */
$documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];

/** @var list<array<string, mixed>> $medias */
$medias = is_array($data['medias'] ?? null) ? $data['medias'] : [];

/** @var array<int, int> $usages */
$usages = is_array($data['usages'] ?? null) ? $data['usages'] : [];

/** @var list<array{nom: string, motif: string}> $refus */
$refus = is_array($data['refus'] ?? null) ? $data['refus'] : [];

?>
<div class="admin-page">
    <h1>Médiathèque</h1>

    <?php if (is_string($data['succes'] ?? null)) : ?>
    <p class="succes" role="status"><?= e($data['succes']) ?></p>
    <?php endif; ?>

    <?php if (is_string($data['erreur'] ?? null)) : ?>
    <p class="erreur" role="alert"><?= e($data['erreur']) ?></p>
    <?php endif; ?>

    <?php if ($refus !== []) : ?>
    <div class="erreur" role="alert">
        <p>Ces fichiers n’ont pas été acceptés :</p>
        <ul>
        <?php foreach ($refus as $refuse) : ?>
            <li><strong><?= e($refuse['nom']) ?></strong> — <?= e($refuse['motif']) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= attr($base . '/admin/medias') ?>"
          enctype="multipart/form-data" class="formulaire">
        <input type="hidden" name="_token" value="<?= attr($jeton) ?>">

        <p class="champ">
            <label for="images">Ajouter des images</label>
            <input type="file" id="images" name="images[]" multiple
                   accept="image/jpeg,image/png,image/webp" required>
            <span class="champ-aide">
                JPEG, PNG ou WebP, <?= e($limiteMo . ' Mo') ?> au maximum par fichier. Les fichiers
                affichés sur le site sont ré-encodés : les données de l’appareil photo, géolocalisation
                comprise, n’y figurent pas. L’original est conservé tel quel pour l’impression, hors
                du site public.
            </span>
        </p>

        <p class="actions">
            <button type="submit" class="bouton">Téléverser</button>
        </p>
    </form>

    <?php /* Compte rendu client du 2026-10-12 : « dans la médiathèque on ne peut
             envoyer que de l'image ? » — le PDF du livret se dépose sur la fiche
             de sa page ; c'est ici que l'artiste l'a cherché. */ ?>
    <?php if ($documents !== []) : ?>
    <section aria-labelledby="documents-titre">
        <h2 id="documents-titre">Documents PDF</h2>
        <p class="aide">
            La médiathèque range les images. Un PDF — le livret, par exemple — se dépose sur la fiche
            de sa page : un bouton « Télécharger » s’affiche alors sur la page publique.
        </p>
        <div class="tableau-defilant">
            <table class="tableau">
                <thead>
                    <tr>
                        <th scope="col">Page</th>
                        <th scope="col">Document</th>
                        <th scope="col" class="colonne-actions">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $document) : ?>
                    <?php $fiche = $base . '/admin/pages/' . $document['id'] . '#document-pdf'; ?>
                    <tr>
                        <td><?= e($document['titre']) ?></td>
                        <?php if ($document['depose']) : ?>
                        <td><a href="<?= attr($url->route('page.document', ['code' => $document['code']])) ?>" target="_blank" rel="noopener">Voir le PDF</a></td>
                        <td><a class="bouton bouton--secondaire" href="<?= attr($fiche) ?>">Remplacer</a></td>
                        <?php else : ?>
                        <td>Aucun PDF</td>
                        <td><a class="bouton bouton--secondaire" href="<?= attr($fiche) ?>">Déposer un PDF</a></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <h2><?= e($data['total'] ?? 0) ?> image(s)</h2>

    <?php if ($medias === []) : ?>
    <p class="aide">La médiathèque est vide.</p>
    <?php else : ?>
    <ul class="grille-medias">
    <?php foreach ($medias as $media) : ?>
        <li>
            <?php // Cliquer sur la vignette ouvre la fiche : edition, remplacement, recadrage. ?>
            <a class="vignette-media-lien" href="<?= attr($base . '/admin/medias/' . $media['id']) ?>">
                <figure class="vignette-media">
                    <img src="<?= attr($url->media($media['public_basename'] . '-320.jpg')) ?>"
                         alt="<?= attr($media['original_name'] ?? '') ?>"
                         width="320" loading="lazy">

                    <figcaption>
                        <?= e($media['original_name'] ?? 'Sans nom') ?><br>
                        <?= e($media['width']) ?> × <?= e($media['height']) ?> px
                        <?php if (($usages[$media['id']] ?? 0) > 0) : ?>
                        <br><span class="pastille">utilisée <?= e($usages[$media['id']]) ?> fois</span>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            </a>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
