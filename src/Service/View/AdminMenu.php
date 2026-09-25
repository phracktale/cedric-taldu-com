<?php

declare(strict_types=1);

namespace App\Service\View;

/**
 * Menu du back-office, regroupé en rubriques (retours du 2026-09-25).
 *
 * Source unique : la mise en page l'affiche en rubriques déroulantes. Une
 * entrée `null` est un séparateur. Les écrans à venir (carte interactive,
 * paramètres globaux, modèles) s'ajoutent ici quand ils existent.
 */
final class AdminMenu
{
    /**
     * @return list<array{label: string, items: list<array{chemin: string, libelle: string}|null>}>
     */
    public static function groups(): array
    {
        return [
            ['label' => 'Contenus', 'items' => [
                ['chemin' => '/admin/medias', 'libelle' => 'Médiathèque'],
                ['chemin' => '/admin/accueil', 'libelle' => 'Accueil'],
                ['chemin' => '/admin/pages', 'libelle' => 'Pages'],
                ['chemin' => '/admin/actus', 'libelle' => 'Actus'],
            ]],
            ['label' => 'Boutique', 'items' => [
                ['chemin' => '/admin/galeries', 'libelle' => 'Galeries'],
                ['chemin' => '/admin/oeuvres', 'libelle' => 'Œuvres'],
                null,
                ['chemin' => '/admin/facturation', 'libelle' => 'Facturation'],
                ['chemin' => '/admin/commandes', 'libelle' => 'Commandes'],
                ['chemin' => '/admin/livraison', 'libelle' => 'Livraisons'],
            ]],
            ['label' => 'Modules', 'items' => [
                ['chemin' => '/admin/messages', 'libelle' => 'Messages'],
                ['chemin' => '/admin/newsletter', 'libelle' => 'Newsletter'],
            ]],
            ['label' => 'Paramètres', 'items' => [
                ['chemin' => '/admin/apparence', 'libelle' => 'Apparence'],
                ['chemin' => '/admin/menu', 'libelle' => 'Menu'],
                ['chemin' => '/admin/templates', 'libelle' => 'Templates'],
                ['chemin' => '/admin/ecoindex', 'libelle' => 'EcoIndex'],
            ]],
        ];
    }

    /**
     * Rubrique contenant le chemin demandé (écran ou sous-écran), ou null.
     */
    public static function groupOf(string $path): ?string
    {
        foreach (self::groups() as $groupe) {
            foreach ($groupe['items'] as $item) {
                if ($item !== null && ($path === $item['chemin'] || str_starts_with($path, $item['chemin'] . '/'))) {
                    return $groupe['label'];
                }
            }
        }

        return null;
    }

    /**
     * L'entrée correspond-elle à la page courante (écran ou sous-écran) ?
     */
    public static function isCurrent(string $path, string $chemin): bool
    {
        return $path === $chemin || str_starts_with($path, $chemin . '/');
    }
}
