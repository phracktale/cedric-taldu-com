<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Editorial\ContentTemplate;
use App\Domain\Editorial\HomeSectionForm;
use App\Repository\Admin\PageAdminRepository;
use App\Repository\Admin\PostAdminRepository;
use App\Repository\Admin\RevisionRepository;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\ContentBlockRepository;
use App\Service\View\AdminChrome;
use DateInterval;

/**
 * Paramètres › Historique (demande du 2026-09-30).
 *
 * Liste les éléments modifiés, montre leurs versions, restaure l'une d'elles —
 * la restauration garde à son tour l'état qu'elle remplace, elle se défait donc
 * comme n'importe quelle modification. La purge, elle, est irréversible : il
 * faut taper PURGER, et elle est inscrite au journal d'audit.
 */
final class HistoryController
{
    public const CONFIRMATION = 'PURGER';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly RevisionRepository $revisions,
        private readonly SettingsAdminRepository $settings,
        private readonly ContentBlockRepository $blocks,
        private readonly PageAdminRepository $pages,
        private readonly PostAdminRepository $posts,
    ) {
    }

    public function index(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        $sujets = array_map(
            static fn (array $s): array => [...$s, 'titre' => self::title($s['type'], $s['key'], $s['label'])],
            $this->revisions->subjects(),
        );

        return $this->chrome->page($request, 'admin/historique/index', [
            'titre' => 'Historique',
            'sujets' => $sujets,
            'stats' => $this->revisions->stats(),
            'confirmation' => self::CONFIRMATION,
            'erreur' => $erreur,
            'purge' => $request->query('purge'),
            'restaure' => $request->query('restaure') !== null,
        ], $status);
    }

    public function show(Request $request): Response
    {
        $type = (string) $request->attribute('type');
        $key = (string) $request->attribute('key');
        $versions = $this->revisions->history($type, $key);

        if ($versions === []) {
            throw new NotFoundException('Aucun historique pour cet élément.');
        }

        return $this->chrome->page($request, 'admin/historique/fiche', [
            'titre' => 'Historique',
            'sujet' => self::title($type, $key, $versions[0]['label']),
            'versions' => $versions,
            'restaure' => $request->query('restaure') !== null,
        ]);
    }

    public function restore(Request $request): Response
    {
        $version = $this->revisions->find((int) $request->attribute('id'));

        if ($version === null) {
            throw new NotFoundException('Version introuvable.');
        }

        $now = $this->chrome->now();
        $etat = $version['snapshot'];

        if ($version['type'] === 'setting' && is_array($etat)) {
            $this->settings->save($version['key'], $etat, $now, 'restore');
        } elseif ($version['type'] === 'content_block' && is_array($etat) && is_string($etat['name'] ?? null)) {
            $this->blocks->restore((int) $version['key'], [
                'name' => $etat['name'],
                'blocks_fr' => is_string($etat['blocks_fr'] ?? null) ? $etat['blocks_fr'] : null,
                'blocks_en' => is_string($etat['blocks_en'] ?? null) ? $etat['blocks_en'] : null,
            ], $now);
        } elseif ($version['type'] === 'page' && is_array($etat)) {
            $this->pages->restore((int) $version['key'], self::stringKeys($etat), $now);
        } elseif ($version['type'] === 'post' && is_array($etat)) {
            $this->posts->restore((int) $version['key'], self::stringKeys($etat), $now);
        } else {
            throw new NotFoundException('Cette version ne peut pas être restaurée.');
        }

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'revisions.restore',
            $request,
            $version['type'],
            null,
            ['version' => $version['id'], 'cle' => $version['key']],
        );

        return RedirectResponse::to(
            $request->basePath . '/admin/historique/' . $version['type'] . '/' . rawurlencode($version['key']) . '?restaure=1',
        );
    }

    public function purge(Request $request): Response
    {
        if ($request->input('confirmation') !== self::CONFIRMATION) {
            return $this->index($request, 'Tapez PURGER, en majuscules, pour confirmer la purge.', 422);
        }

        $avant = null;
        if ($request->input('portee') === 'anciennes') {
            $jours = (int) ($request->input('jours') ?? '0');
            if ($jours < 1 || $jours > 3650) {
                return $this->index($request, 'Indiquez un nombre de jours entre 1 et 3650.', 422);
            }
            $avant = $this->chrome->now()->sub(new DateInterval('P' . $jours . 'D'));
        }

        $effacees = $this->revisions->purge($avant);

        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'revisions.purge',
            $request,
            'revision',
            null,
            ['versions' => $effacees, 'avant' => $avant?->format('Y-m-d H:i:s')],
        );

        return RedirectResponse::to($request->basePath . '/admin/historique?purge=' . $effacees);
    }

    /**
     * @param  array<mixed>         $state
     * @return array<string, mixed>
     */
    private static function stringKeys(array $state): array
    {
        $clean = [];
        foreach ($state as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * Nom lisible d'un élément de l'historique.
     */
    public static function title(string $type, string $key, string $label): string
    {
        if ($type === 'content_block') {
            return 'Bloc « ' . $label . ' »';
        }

        if ($type === 'page') {
            return 'Page « ' . $label . ' »';
        }

        if ($type === 'post') {
            return 'Actu « ' . $label . ' »';
        }

        if (str_starts_with($key, 'template.')) {
            $libelle = ContentTemplate::TYPES[substr($key, 9)][0] ?? substr($key, 9);

            return 'Template « ' . $libelle . ' »';
        }

        $section = array_search($key, HomeSectionForm::SECTIONS, true);
        if (is_string($section)) {
            return 'Accueil — section « ' . $section . ' »';
        }

        return match ($key) {
            'home.layout' => 'Accueil — ordre et blocs des sections',
            'nav.menu' => 'Menu principal',
            'nav.footer' => 'Pied de page',
            'site.identity' => 'Identité du site',
            'contact.page' => 'Page contact',
            'map' => 'Carte interactive',
            'print.settings' => 'Formats d’impression',
            'shipping' => 'Livraisons',
            default => 'Réglage « ' . $key . ' »',
        };
    }
}
