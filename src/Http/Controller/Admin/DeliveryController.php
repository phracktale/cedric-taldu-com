<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Exception\InvalidAddress;
use App\Domain\Order\Address;
use App\Domain\Shipping\HandDeliveryZone;
use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use App\Service\Shipping\CarrierRegistry;
use App\Service\Shipping\Geocoder;
use App\Service\View\AdminChrome;

/**
 * Écran « Livraison » (revue du 2026-09-24).
 *
 * - Zone de remise en main propre : l'artiste saisit l'adresse de son atelier et
 *   un rayon ; l'adresse est géocodée ici (BAN) et seules ses coordonnées sont
 *   gardées dans le réglage `shipping.hand_delivery`.
 * - État des transporteurs : Colissimo, API active ou en attente d'identifiants.
 */
final class DeliveryController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly Geocoder $geocoder,
        private readonly CarrierRegistry $carriers,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->form($request);
    }

    public function update(Request $request): Response
    {
        $lieu = trim((string) $request->input('lieu'));
        $rayon = (string) $request->input('rayon');

        try {
            $adresse = new Address(
                (string) $request->input('adresse'),
                null,
                (string) $request->input('code_postal'),
                $lieu,
                'FR',
            );
        } catch (InvalidAddress) {
            return $this->form($request, 'Adresse de l’atelier incomplète.', 422);
        }

        $point = $this->geocoder->locate($adresse);

        if ($point === null) {
            return $this->form($request, 'Adresse introuvable : vérifiez l’adresse et le code postal de l’atelier.', 422);
        }

        // HandDeliveryZone borne le rayon et contrôle les coordonnées à la relecture.
        $zone = HandDeliveryZone::fromSetting([
            'lat' => $point->latitude,
            'lng' => $point->longitude,
            'radius_km' => ctype_digit($rayon) ? (int) $rayon : null,
            'place' => $lieu,
        ]);

        $this->save->save(HandDeliveryZone::SETTING, [
            'lat' => $zone->origin->latitude,
            'lng' => $zone->origin->longitude,
            'radius_km' => $zone->radiusKm,
            'place' => $zone->placeName,
        ], $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), HandDeliveryZone::SETTING, $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/livraison');
    }

    private function form(Request $request, ?string $erreur = null, int $status = 200): Response
    {
        $zone = HandDeliveryZone::fromSetting($this->settings->json(HandDeliveryZone::SETTING));

        return $this->chrome->page($request, 'admin/livraison/index', [
            'titre' => 'Livraison',
            'zone' => $zone,
            'transporteurs' => $this->carriers->all(),
            'erreur' => $erreur,
        ], $status);
    }
}
