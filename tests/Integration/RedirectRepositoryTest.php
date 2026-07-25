<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\RedirectRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\DatabaseTestCase;

/**
 * Redirections 301 au changement de slug (05-i18n-seo §5).
 *
 * « Les chaînes de redirection sont résolues à l'écriture pour éviter les
 * rebonds successifs. » Une seule redirection par chemin d'origine.
 */
#[CoversClass(RedirectRepository::class)]
final class RedirectRepositoryTest extends DatabaseTestCase
{
    private function repository(): RedirectRepository
    {
        return new RedirectRepository($this->pdo);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_une_redirection_est_enregistree_et_retrouvee(): void
    {
        $repo = $this->repository();
        $repo->record('fr', '/fr/galerie/ancien', '/fr/galerie/nouveau', $this->now());

        $this->assertSame('/fr/galerie/nouveau', $repo->findTarget('fr', '/fr/galerie/ancien'));
        $this->assertNull($repo->findTarget('fr', '/fr/galerie/inconnu'));
    }

    public function test_une_redirection_vers_soi_meme_n_est_pas_enregistree(): void
    {
        $repo = $this->repository();
        $repo->record('fr', '/fr/galerie/x', '/fr/galerie/x', $this->now());

        $this->assertNull($repo->findTarget('fr', '/fr/galerie/x'));
    }

    public function test_les_chaines_sont_aplaties_a_l_ecriture(): void
    {
        // A→B puis B→C doit donner A→C ET B→C, jamais un rebond A→B→C.
        $repo = $this->repository();
        $repo->record('fr', '/fr/galerie/a', '/fr/galerie/b', $this->now());
        $repo->record('fr', '/fr/galerie/b', '/fr/galerie/c', $this->now());

        $this->assertSame('/fr/galerie/c', $repo->findTarget('fr', '/fr/galerie/a'));
        $this->assertSame('/fr/galerie/c', $repo->findTarget('fr', '/fr/galerie/b'));
    }

    public function test_le_nouveau_slug_cesse_de_rediriger(): void
    {
        // Si l'on redirige vers un chemin qui était lui-même une source de
        // redirection, ce chemin redevient du contenu vivant : plus de redirection.
        $repo = $this->repository();
        $repo->record('fr', '/fr/galerie/a', '/fr/galerie/b', $this->now());
        $repo->record('fr', '/fr/galerie/c', '/fr/galerie/a', $this->now());

        // /a redevient une destination : il ne redirige plus.
        $this->assertNull($repo->findTarget('fr', '/fr/galerie/a'));
    }

    public function test_reenregistrer_le_meme_chemin_met_a_jour_la_cible(): void
    {
        $repo = $this->repository();
        $repo->record('fr', '/fr/galerie/a', '/fr/galerie/b', $this->now());
        $repo->record('fr', '/fr/galerie/a', '/fr/galerie/d', $this->now());

        $this->assertSame('/fr/galerie/d', $repo->findTarget('fr', '/fr/galerie/a'));
    }
}
