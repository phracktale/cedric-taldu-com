<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use APress\Core\Exception\NotFoundException;
use APress\Core\Extension\ExtensionManager;
use APress\Core\Extension\ExtensionRefused;
use APress\Core\RedirectResponse;
use APress\Core\Request;
use APress\Core\Response;
use APress\Core\SessionInterface;
use APress\Core\Auth\AdminSession;
use App\Service\View\AdminChrome;
use PDO;
use Throwable;

/**
 * Page « Extensions » (A-Press, lot 2) : activer et désactiver les extensions
 * du dossier extensions/.
 *
 * Réservée aux comptes qui gèrent les réglages : un compte de rédaction reçoit
 * une 404, comme pour toute ressource qu'il n'a pas à connaître. Désactiver ne
 * supprime aucune donnée ; activer applique les migrations de l'extension dans
 * une transaction, avec l'enregistrement de son état.
 */
final class ExtensionController
{
    private const MESSAGE = 'admin.extensions.message';

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly AdminSession $session,
        private readonly ExtensionManager $extensions,
        private readonly SessionInterface $flash,
        private readonly PDO $pdo,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize();

        $message = $this->flash->get(self::MESSAGE);
        $this->flash->remove(self::MESSAGE);

        return $this->chrome->page($request, 'admin/extensions/index', [
            'titre' => 'Extensions',
            'extensions' => $this->extensions->all(),
            'message' => $message,
        ]);
    }

    public function activate(Request $request): Response
    {
        $this->authorize();
        $name = self::name($request);

        return $this->change($request, $name, 'extension.activate', function () use ($name): string {
            $this->extensions->activate($name);

            return sprintf('L’extension « %s » est activée.', $name);
        });
    }

    public function deactivate(Request $request): Response
    {
        $this->authorize();
        $name = self::name($request);

        return $this->change($request, $name, 'extension.deactivate', function () use ($name): string {
            $this->extensions->deactivate($name);

            return sprintf('L’extension « %s » est désactivée. Ses données sont conservées.', $name);
        });
    }

    /**
     * @param callable(): string $action
     */
    private function change(Request $request, string $name, string $audit, callable $action): Response
    {
        $own = !$this->pdo->inTransaction();

        try {
            if ($own) {
                $this->pdo->beginTransaction();
            }
            $message = $action();
            $this->chrome->audit()->record($this->chrome->currentUserId(), $audit, $request, 'extension', null, ['extension' => $name]);
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (ExtensionRefused $refus) {
            $this->rollBack($own);
            $message = $refus->getMessage();
        } catch (Throwable $exception) {
            $this->rollBack($own);
            $message = 'L’opération a échoué ; rien n’a été modifié. Détail dans le journal.';
            $this->chrome->audit()->record($this->chrome->currentUserId(), $audit . '_failed', $request, 'extension', null, ['extension' => $name]);
            throw $exception;
        } finally {
            if (isset($message)) {
                $this->flash->set(self::MESSAGE, $message);
            }
        }

        return RedirectResponse::to($request->basePath . '/admin/extensions');
    }

    private function rollBack(bool $own): void
    {
        if ($own && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Nom pris dans l'URL : la route le contraint déjà à un slug, et le
     * gestionnaire ne s'en sert que comme clé, jamais comme chemin.
     */
    private static function name(Request $request): string
    {
        $name = $request->attribute('name');

        return is_string($name) ? $name : '';
    }

    private function authorize(): void
    {
        $user = $this->session->currentUser();

        if ($user === null || !$user->role->canManageSettings()) {
            throw new NotFoundException();
        }
    }
}
