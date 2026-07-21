<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * Role d'un compte d'administration.
 *
 * 04-back-office §1 : `admin` peut tout ; `editor` touche au contenu editorial
 * et au catalogue, mais PAS aux commandes, aux reglages ni aux utilisateurs.
 *
 * Les droits sont exprimes par des methodes nommees plutot que par une liste de
 * permissions : une capacite ajoutee au lot 3 devra etre decrite ici, et un
 * `match` exhaustif fera echouer l'analyse statique tant que chaque role n'aura
 * pas ete tranche. Un droit oublie devient une erreur de compilation, pas un
 * acces ouvert.
 */
enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    /** Rubriques, series, œuvres, medias, articles, pages. */
    public function canManageCatalog(): bool
    {
        return match ($this) {
            self::Admin, self::Editor => true,
        };
    }

    /** Commandes, expeditions, exports comptables. */
    public function canManageOrders(): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Editor => false,
        };
    }

    /** Reglages, dont le regime de TVA et les frais de port. */
    public function canManageSettings(): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Editor => false,
        };
    }

    /** Creation et suppression de comptes d'administration. */
    public function canManageUsers(): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Editor => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Editor => 'Éditeur',
        };
    }
}
