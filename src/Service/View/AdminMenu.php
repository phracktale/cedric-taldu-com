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
     * @param list<array{chemin: string, libelle: string, groupe?: string}> $extensions entrées
     *        déclarées par les extensions actives (A-Press, lots 2 et 3). Une entrée
     *        rejoint la rubrique qu'elle nomme (après un séparateur, ou avant
     *        « Extensions » dans Paramètres) ; sans rubrique connue, elle va dans
     *        une rubrique « Extensions », omise si vide.
     * @return list<array{label: string, items: list<array{chemin: string, libelle: string}|null>}>
     */
    public static function groups(array $extensions = []): array
    {
        $groupes = self::base();
        $autres = [];
        $separe = [];

        foreach ($extensions as $entree) {
            $item = ['chemin' => $entree['chemin'], 'libelle' => $entree['libelle']];
            $index = array_search($entree['groupe'] ?? '', array_column($groupes, 'label'), true);

            if ($index === false) {
                $autres[] = $item;
            } elseif ($groupes[$index]['label'] === 'Paramètres') {
                // Avant « Extensions » et « Historique », qui ferment la rubrique.
                array_splice($groupes[$index]['items'], count($groupes[$index]['items']) - 2, 0, [$item]);
            } else {
                if (!isset($separe[$index])) {
                    $groupes[$index]['items'][] = null;
                    $separe[$index] = true;
                }
                $groupes[$index]['items'][] = $item;
            }
        }

        if ($autres !== []) {
            // Avant « Paramètres », qui reste la dernière rubrique.
            array_splice($groupes, count($groupes) - 1, 0, [['label' => 'Extensions', 'items' => $autres]]);
        }

        return $groupes;
    }

    /**
     * Rubriques du site lui-même, sans extension. Les écrans de la boutique
     * (facturation, commandes, livraisons, impression) viennent de l'extension.
     *
     * @return list<array{label: string, items: list<array{chemin: string, libelle: string}|null>}>
     */
    private static function base(): array
    {
        return [
            ['label' => 'Contenus', 'items' => [
                ['chemin' => '/admin/medias', 'libelle' => 'Médiathèque'],
                ['chemin' => '/admin/accueil', 'libelle' => 'Accueil'],
                ['chemin' => '/admin/pages', 'libelle' => 'Pages'],
                ['chemin' => '/admin/contact', 'libelle' => 'Contact'],
                ['chemin' => '/admin/actus', 'libelle' => 'Actus'],
                ['chemin' => '/admin/blocs', 'libelle' => 'Blocs'],
            ]],
            ['label' => 'Boutique', 'items' => [
                ['chemin' => '/admin/galeries', 'libelle' => 'Galeries'],
                ['chemin' => '/admin/oeuvres', 'libelle' => 'Œuvres'],
            ]],
            ['label' => 'Modules', 'items' => [
                ['chemin' => '/admin/messages', 'libelle' => 'Messages'],
                ['chemin' => '/admin/newsletter', 'libelle' => 'Newsletter'],
                ['chemin' => '/admin/carte', 'libelle' => 'Carte interactive'],
            ]],
            ['label' => 'Paramètres', 'items' => [
                ['chemin' => '/admin/global', 'libelle' => 'Global'],
                ['chemin' => '/admin/apparence', 'libelle' => 'Apparence'],
                ['chemin' => '/admin/menu', 'libelle' => 'Menu'],
                ['chemin' => '/admin/templates', 'libelle' => 'Templates'],
                ['chemin' => '/admin/ecoindex', 'libelle' => 'EcoIndex'],
                // Extensions A-Press (lot 2).
                ['chemin' => '/admin/extensions', 'libelle' => 'Extensions'],
                // Historique des versions (demande du 2026-09-30), toujours en dernier.
                ['chemin' => '/admin/historique', 'libelle' => 'Historique'],
            ]],
        ];
    }

    /**
     * Rubrique contenant le chemin demandé (écran ou sous-écran), ou null.
     *
     * @param list<array{chemin: string, libelle: string, groupe?: string}> $extensions
     */
    public static function groupOf(string $path, array $extensions = []): ?string
    {
        foreach (self::groups($extensions) as $groupe) {
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
