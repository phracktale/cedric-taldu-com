<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Rule;
use App\Core\Validator;
use App\Domain\Locale;
use App\Repository\Admin\MediaAdminRepository;
use App\Service\Media\Exception\UploadRejected;
use App\Service\Media\MediaStore;
use App\Service\View\AdminChrome;

/**
 * Mediatheque (04-back-office §7).
 *
 * Le televersement multiple par glisser-deposer est une AMELIORATION (§12) : ce
 * controleur accepte un formulaire ordinaire a champ multiple, et fonctionne
 * sans une ligne de JavaScript.
 *
 * Toute la securite du televersement vit dans MediaStore et UploadValidator
 * (06-securite §5). Ici, on traduit un refus en message francais et on le
 * journalise — « Journalisation des evenements de securite : [...] uploads
 * refuses » (§10).
 */
final class MediaController
{
    private const PAR_PAGE = 48;

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly MediaAdminRepository $medias,
        private readonly MediaStore $store,
        private readonly Validator $validator,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function upload(Request $request): Response
    {
        $fichiers = $request->files('images');

        if ($fichiers === []) {
            return $this->page($request, erreur: 'Aucun fichier n’a été reçu.', status: 422);
        }

        $ajoutes = 0;
        $doublons = 0;
        $refus = [];

        foreach ($fichiers as $fichier) {
            try {
                $resultat = $this->store->store($fichier);

                if ($resultat->wasDuplicate) {
                    $doublons++;
                    continue;
                }

                $ajoutes++;
                $this->chrome->audit()->record(
                    $this->userId(),
                    'media.upload',
                    $request,
                    'media',
                    $resultat->id,
                );
            } catch (UploadRejected $exception) {
                // Le nom affiche est celui du client, echappe par le gabarit.
                // Le journal, lui, recoit le MOTIF technique et jamais le nom.
                $refus[] = ['nom' => $fichier->clientName, 'motif' => $exception->reason()->message()];

                $this->chrome->audit()->record(
                    $this->userId(),
                    'media.upload_rejected',
                    $request,
                    meta: ['motif' => $exception->reason()->value],
                );
            }
        }

        return $this->page(
            $request,
            succes: $this->resume($ajoutes, $doublons),
            refus: $refus,
            status: $refus === [] ? 200 : 422,
        );
    }

    /**
     * Texte alternatif, legende et point focal.
     */
    public function update(Request $request): Response
    {
        $media = $this->media($request);

        $saisie = $this->validator->validate($request->post, [
            'alt' => Rule::text(255, required: false),
            'caption' => Rule::text(255, required: false),
        ]);

        $this->medias->replaceTranslations((int) $media['id'], [
            Locale::reference()->value => [
                'alt' => (string) ($saisie['alt'] ?? ''),
                'caption' => isset($saisie['caption']) ? (string) $saisie['caption'] : null,
            ],
        ]);

        $this->medias->updateFocalPoint(
            (int) $media['id'],
            self::pourcentage($request->input('focal_x')),
            self::pourcentage($request->input('focal_y')),
        );

        $this->chrome->audit()->record($this->userId(), 'media.update', $request, 'media', (int) $media['id']);

        return RedirectResponse::to($request->basePath . '/admin/medias');
    }

    public function delete(Request $request): Response
    {
        $media = $this->media($request);
        $usages = $this->medias->usageOf((int) $media['id']);

        // 04-back-office §7 : « Suppression refusee si le media est utilise. »
        if (array_sum($usages) > 0) {
            return $this->page(
                $request,
                erreur: 'Cette image est utilisée : retirez-la d’abord des œuvres et rubriques concernées.',
                status: 409,
            );
        }

        $this->store->remove((int) $media['id']);
        $this->chrome->audit()->record($this->userId(), 'media.delete', $request, 'media', (int) $media['id']);

        return RedirectResponse::to($request->basePath . '/admin/medias');
    }

    // -------------------------------------------------------------- interne

    /**
     * @param list<array{nom: string, motif: string}> $refus
     */
    private function page(
        Request $request,
        ?string $succes = null,
        ?string $erreur = null,
        array $refus = [],
        int $status = 200,
    ): Response {
        $medias = $this->medias->findRecent(self::PAR_PAGE);
        $usages = [];

        foreach ($medias as $media) {
            $usages[(int) $media['id']] = array_sum($this->medias->usageOf((int) $media['id']));
        }

        return $this->chrome->page($request, 'admin/medias/index', [
            'titre' => 'Médiathèque',
            'medias' => $medias,
            'usages' => $usages,
            'total' => $this->medias->countAll(),
            'succes' => $succes,
            'erreur' => $erreur,
            'refus' => $refus,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function media(Request $request): array
    {
        $id = $request->attribute('id');

        // L'identifiant vient du chemin et a deja passe Route::ID, mais le
        // controleur ne s'appuie pas sur cette garantie : il la revalide.
        $media = ctype_digit((string) $id) ? $this->medias->findById((int) $id) : null;

        return $media ?? throw new NotFoundException('Média introuvable.');
    }

    private function userId(): ?int
    {
        return $this->chrome->currentUserId();
    }

    private function resume(int $ajoutes, int $doublons): ?string
    {
        $parties = [];

        if ($ajoutes > 0) {
            $parties[] = $ajoutes === 1 ? '1 image ajoutée' : $ajoutes . ' images ajoutées';
        }

        if ($doublons > 0) {
            // L'artiste doit savoir que l'image etait DEJA la, sinon il croit
            // avoir echoue et recommence.
            $parties[] = $doublons === 1
                ? '1 image était déjà dans la médiathèque'
                : $doublons . ' images étaient déjà dans la médiathèque';
        }

        return $parties === [] ? null : ucfirst(implode(', ', $parties)) . '.';
    }

    /**
     * Point focal : un pourcentage entier, ou rien.
     */
    private static function pourcentage(?string $valeur): ?int
    {
        if ($valeur === null || !ctype_digit($valeur)) {
            return null;
        }

        $nombre = (int) $valeur;

        return $nombre <= 100 ? $nombre : null;
    }
}
