<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Contact\MessageStatus;
use App\Domain\Locale;
use App\Repository\ArtworkRepository;
use App\Repository\ContactMessageRepository;
use App\Service\View\AdminChrome;

/**
 * Boîte de réception des messages de contact (04-back-office §10).
 *
 * La réponse ne passe PAS par le site : l'artiste répond depuis son client de
 * messagerie, via un lien `mailto:` pré-rempli. Le site ne fait que recevoir,
 * classer et conserver. Consulter un message neuf le marque « lu ».
 */
final class MessageController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly ContactMessageRepository $messages,
        private readonly ArtworkRepository $artworks,
    ) {
    }

    public function index(Request $request): Response
    {
        $filter = self::statusFilter($request->query('statut'));

        return $this->chrome->page($request, 'admin/messages/index', [
            'titre' => 'Messages',
            'messages' => $this->messages->findAll($filter, self::PER_PAGE, 0),
            'filtre' => $filter,
            'compte' => [
                'tous' => $this->messages->countByStatus(null),
                'new' => $this->messages->countByStatus(MessageStatus::New),
                'answered' => $this->messages->countByStatus(MessageStatus::Answered),
                'spam' => $this->messages->countByStatus(MessageStatus::Spam),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $message = $this->message($request);

        // Ouvrir un message neuf le marque lu : le tableau de bord compte les
        // messages « non lus », il doit refléter ce qui a été vu.
        if ($message->status === MessageStatus::New && $message->id !== null) {
            $this->messages->updateStatus($message->id, MessageStatus::Read);
        }

        $artwork = $message->artworkId === null ? null : $this->artworks->findById($message->artworkId);

        return $this->chrome->page($request, 'admin/messages/detail', [
            'titre' => 'Message',
            'message' => $message,
            'oeuvre' => $artwork,
            'oeuvreTitre' => $artwork?->title(Locale::Fr),
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $message = $this->message($request);
        $id = (int) $message->id;

        $status = self::statusFilter($request->input('statut'));

        // Liste close : un statut hors des quatre valeurs est ignoré, jamais
        // interpolé (06-securite §1). `null` (aucun statut valide) ne fait rien.
        if ($status !== null) {
            $this->messages->updateStatus($id, $status);
            $this->chrome->audit()->record(
                $this->chrome->currentUserId(),
                'message.status',
                $request,
                'contact_message',
                $id,
                ['status' => $status->value],
            );
        }

        return RedirectResponse::to($request->basePath . '/admin/messages/' . $id);
    }

    public function delete(Request $request): Response
    {
        $message = $this->message($request);
        $id = (int) $message->id;

        $this->messages->delete($id);
        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            'message.delete',
            $request,
            'contact_message',
            $id,
        );

        return RedirectResponse::to($request->basePath . '/admin/messages');
    }

    // -------------------------------------------------------------- interne

    private function message(Request $request): \App\Domain\Contact\ContactMessage
    {
        $id = $request->attribute('id');

        $message = ctype_digit((string) $id) ? $this->messages->findById((int) $id) : null;

        return $message ?? throw new NotFoundException('Message introuvable.');
    }

    private static function statusFilter(?string $value): ?MessageStatus
    {
        return $value === null ? null : MessageStatus::tryFrom($value);
    }
}
