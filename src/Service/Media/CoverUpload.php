<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Core\Request;
use App\Service\Media\Exception\UploadRejected;

/**
 * Resout l'image de couverture d'une entite depuis un formulaire.
 *
 * 04-back-office §7 : depuis une rubrique, une actualite, une œuvre ou une page,
 * on doit pouvoir televerser une image DIRECTEMENT dans la mediatheque, sans
 * passer par un aller-retour manuel. Ce service unifie les deux voies :
 *
 *  - un fichier est joint → il est enregistre (MediaStore : validation,
 *    re-encodage, dedoublonnage, derives) et son identifiant est retenu ;
 *  - sinon, on retombe sur l'identifiant numerique saisi a la main.
 *
 * Le fichier prime : joindre une image ET saisir un numero signifie « prends la
 * nouvelle ». Aucune ligne de JavaScript n'est requise (§12) — c'est un
 * `<input type="file">` ordinaire, traite a l'enregistrement du formulaire.
 */
final class CoverUpload
{
    public function __construct(private readonly MediaStore $store)
    {
    }

    /**
     * @throws UploadRejected si le fichier joint est refuse
     */
    public function resolve(Request $request, string $fileField, string $idField): ?int
    {
        $file = $request->file($fileField);

        if ($file !== null) {
            return $this->store->store($file)->id;
        }

        $value = $request->input($idField);

        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
