<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Motif de refus d'un televersement.
 *
 * Chaque cas porte un message destine a l'ARTISTE : il doit lui dire quoi faire
 * de son image, et rien de plus. Aucun chemin serveur, aucun nom de fonction,
 * aucun detail qui renseignerait sur l'implementation (06-securite §10).
 *
 * Le nom du cas, lui, part dans le journal d'audit : c'est la qu'un
 * televersement refuse doit laisser une trace exploitable (06-securite §10,
 * « Journalisation des evenements de securite : [...] uploads refuses »).
 */
enum UploadRejection: string
{
    case Missing = 'missing';
    case Failed = 'failed';
    case Empty = 'empty';
    case TooHeavy = 'too_heavy';
    case ForbiddenType = 'forbidden_type';
    case TooLarge = 'too_large';
    case TooManyPixels = 'too_many_pixels';
    case Corrupt = 'corrupt';
    case Duplicate = 'duplicate';

    public function message(): string
    {
        return match ($this) {
            self::Missing => 'Aucun fichier n’a été reçu.',
            self::Failed => 'Le transfert a été interrompu. Réessayez.',
            self::Empty => 'Ce fichier est vide.',
            self::TooHeavy => 'Ce fichier dépasse la taille maximale de 25 Mo.',
            self::ForbiddenType => 'Seules les images JPEG, PNG et WebP sont acceptées.',
            self::TooLarge => 'Cette image dépasse 12 000 pixels de côté.',
            self::TooManyPixels => 'Cette image est trop grande pour être traitée. Réduisez-la avant de l’envoyer.',
            self::Corrupt => 'Ce fichier est illisible : il est probablement incomplet ou abîmé.',
            self::Duplicate => 'Cette image est déjà présente dans la médiathèque sous un autre média.',
        };
    }
}
