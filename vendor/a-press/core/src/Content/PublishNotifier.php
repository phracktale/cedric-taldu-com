<?php

declare(strict_types=1);

namespace APress\Core\Content;

/**
 * Prévient le générateur du site qu'un contenu PUBLIC a changé : publication,
 * retrait, corbeille. Enregistrer un brouillon ne prévient personne : seul le
 * bouton « Publier » provoque une génération.
 *
 * Une implémentation ne lève jamais d'exception : la publication est déjà
 * enregistrée quand elle est appelée.
 */
interface PublishNotifier
{
    /**
     * @param 'publish'|'unpublish'|'trash'|'untrash' $event
     */
    public function notify(string $type, int $id, string $event): void;
}
