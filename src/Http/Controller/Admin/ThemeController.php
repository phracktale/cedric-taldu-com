<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use APress\Core\Exception\NotFoundException;
use APress\Core\RedirectResponse;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\SessionInterface;
use APress\Core\Theme\ThemeRegistry;
use APress\Core\Theme\ThemeType;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\Auth\AdminSession;
use App\Service\View\AdminChrome;

/**
 * Paramètres › Thèmes (A-Press, lot 4) : habillage du back-office.
 *
 * « classique » désigne la mise en page d'origine (templates/layouts/admin.php)
 * et n'est pas un dossier de thème. Le choix est un réglage (`theme.admin`),
 * historisé comme les autres. Réservé aux comptes qui gèrent les réglages.
 */
final class ThemeController
{
    public const SETTING = 'theme.admin';
    public const CLASSIC = 'classique';
    private const MESSAGE = 'admin.themes.message';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly AdminSession $session,
        private readonly ThemeRegistry $themes,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $settingsAdmin,
        private readonly SessionInterface $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize();

        $message = $this->flash->get(self::MESSAGE);
        $this->flash->remove(self::MESSAGE);

        return $this->chrome->page($request, 'admin/themes/index', [
            'titre' => 'Thèmes',
            'themes' => $this->themes->all(ThemeType::Admin),
            'erreurs' => $this->themes->errors(ThemeType::Admin),
            'actif' => self::active($this->settings),
            'message' => $message,
        ]);
    }

    public function activate(Request $request): Response
    {
        $this->authorize();

        $name = $request->attribute('name');
        $name = is_string($name) ? $name : '';
        $theme = $name === self::CLASSIC ? null : $this->themes->find(ThemeType::Admin, $name);

        if ($name !== self::CLASSIC && $theme === null) {
            $this->flash->set(self::MESSAGE, 'Thème inconnu ou incompatible : rien n’a été changé.');

            return RedirectResponse::to($request->basePath . '/admin/themes');
        }

        $this->settingsAdmin->save(self::SETTING, ['name' => $name], $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'theme.activate', $request, 'setting', null, ['theme' => $name]);
        $this->flash->set(self::MESSAGE, sprintf('Thème « %s » appliqué au back-office.', $theme !== null ? $theme->label : 'Classique'));

        return RedirectResponse::to($request->basePath . '/admin/themes');
    }

    /**
     * Nom du thème d'administration enregistré, « classique » par défaut.
     */
    public static function active(SettingRepository $settings): string
    {
        $name = $settings->json(self::SETTING)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::CLASSIC;
    }

    private function authorize(): void
    {
        $user = $this->session->currentUser();

        if ($user === null || !$user->role->canManageSettings()) {
            throw new NotFoundException();
        }
    }
}
