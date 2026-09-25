<?php

declare(strict_types=1);

namespace App\Service\StaticSite;

use App\Repository\Admin\SettingsAdminRepository;
use App\Repository\SettingRepository;
use DateTimeImmutable;

/**
 * Lecture et écriture de l'état de génération.
 *
 * Lu SANS le cache de SettingRepository : la génération relit l'état après
 * avoir rendu toutes les pages, pour savoir si une invalidation est survenue
 * entre-temps — une lecture en cache ne le verrait jamais.
 */
final class GenerationStore
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly SettingsAdminRepository $save,
    ) {
    }

    public function load(): GenerationState
    {
        $stored = $this->settings->fresh(GenerationState::SETTING);

        return $stored === [] ? GenerationState::none() : GenerationState::fromArray($stored);
    }

    public function save(GenerationState $state, DateTimeImmutable $now): void
    {
        $this->save->save(GenerationState::SETTING, $state->toArray(), $now);
    }
}
