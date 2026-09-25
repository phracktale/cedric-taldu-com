<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Domain\Editorial\SiteIdentity;
use App\Repository\SettingRepository;

/**
 * Identité du site (Paramètres › Global), lue une fois par requête pour les
 * gabarits (View::share) et les données structurées.
 */
final class SiteIdentityProvider
{
    private ?SiteIdentity $identity = null;

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function get(): SiteIdentity
    {
        return $this->identity ??= SiteIdentity::fromStored($this->settings->json(SiteIdentity::SETTING));
    }
}
