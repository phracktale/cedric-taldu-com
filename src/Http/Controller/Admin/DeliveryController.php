<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Exception\InvalidAddress;
use App\Domain\Order\Address;
use App\Domain\Shipping\HandDeliveryZone;
use App\Domain\Shipping\ShippingGridForm;
use App\Repository\Admin\ShippingAdminRepository;
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
 * - Retours du 2026-09-25 : module de livraison choisi, grille de tarifs par
 *   zone et tranche de poids, emballage forfaitaire (réglage `shipping`).
 */
final class DeliveryController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
        private readonly Geocoder $geocoder,
        private readonly CarrierRegistry $carriers,
        private readonly ShippingAdminRepository $grid,
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

    /**
     * Grille de tarifs, emballage et module : tout ou rien — une saisie
     * invalide ne touche à rien et réaffiche le formulaire avec la saisie.
     */
    public function updateRates(Request $request): Response
    {
        $zones = $this->grid->zones();
        $saisie = $request->post;
        $grille = ShippingGridForm::parse($saisie, array_map(static fn (array $z): int => $z['id'], $zones));

        $emballage = trim((string) $request->input('emballage'));
        $erreurs = $grille->errors;
        if (!ctype_digit($emballage) || (int) $emballage > 20000) {
            $erreurs[] = 'Emballage : un poids en grammes, entre 0 et 20000.';
        }
        $transporteur = $this->carriers->byName($request->input('transporteur')) ?? $this->carriers->default();

        if ($erreurs !== []) {
            return $this->form($request, null, 422, $erreurs, $saisie);
        }

        $this->grid->save($grille->zones);
        $this->save->save('shipping', [
            ...$this->settings->json('shipping'),
            'packaging_grams' => (int) $emballage,
            'carrier' => $transporteur->code(),
        ], $this->chrome->now());
        $this->chrome->audit()->record($this->chrome->currentUserId(), 'shipping.rates', $request, 'setting', null);

        return RedirectResponse::to($request->basePath . '/admin/livraison?tarifs=1', 303);
    }

    /**
     * @param list<string>               $erreursTarifs
     * @param array<string, string|null> $saisie saisie de la grille à réafficher
     */
    private function form(
        Request $request,
        ?string $erreur = null,
        int $status = 200,
        array $erreursTarifs = [],
        array $saisie = [],
    ): Response {
        $zone = HandDeliveryZone::fromSetting($this->settings->json(HandDeliveryZone::SETTING));

        return $this->chrome->page($request, 'admin/livraison/index', [
            'titre' => 'Livraison',
            'zone' => $zone,
            'transporteurs' => $this->carriers->all(),
            'transporteur' => $this->carriers->default()->code(),
            'erreur' => $erreur,
            'zones' => $this->grid->zones(),
            'emballage' => $this->settings->json('shipping')['packaging_grams'] ?? 250,
            'erreursTarifs' => $erreursTarifs,
            'saisie' => $saisie,
            'tarifsEnregistres' => $request->query('tarifs') !== null,
        ], $status);
    }
}
