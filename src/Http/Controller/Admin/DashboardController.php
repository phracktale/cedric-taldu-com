<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repository\Admin\DashboardRepository;
use App\Repository\AuditLogRepository;
use App\Service\View\AdminChrome;

/**
 * Tableau de bord.
 *
 * 08-lots : « tableau de bord MINIMAL » au lot 2. Le chiffre d'affaires, les
 * commandes en attente et les messages non lus de 04-back-office §2 arrivent
 * avec les lots qui creent ces donnees — afficher aujourd'hui des compteurs a
 * zero laisserait croire a une boutique deserte plutot qu'a une boutique non
 * encore construite.
 *
 * Ce qui est ici est ce que le lot 2 produit reellement : l'etat du catalogue et
 * les dernieres actions tracees.
 */
final class DashboardController
{
    private const DERNIERES_ACTIONS = 15;

    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly DashboardRepository $catalogue,
        private readonly AuditLogRepository $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->chrome->page($request, 'admin/tableau-de-bord', [
            'titre' => 'Tableau de bord',
            'compteurs' => $this->catalogue->counts(),
            'actions' => $this->audit->findRecent(self::DERNIERES_ACTIONS),
        ]);
    }
}
