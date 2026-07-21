<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\AuditLogRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * 04-back-office §1 : « Toute action modifiant une donnee est tracee dans
 * audit_log (acteur, action, entite, differentiel des champs, IP hachee). »
 *
 * Deux exigences se croisent ici et se contredisent en apparence : le journal
 * doit etre exploitable pour comprendre ce qui s'est passe, et il ne doit pas
 * devenir lui-meme une fuite. D'ou l'IP hachee (06-securite §9) et un
 * differentiel qui ne recopie jamais un secret.
 */
final class AuditLogRepositoryTest extends DatabaseTestCase
{
    private AuditLogRepository $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = new AuditLogRepository($this->pdo);
    }

    public function test_une_action_est_tracee_avec_son_acteur_et_son_entite(): void
    {
        $utilisateur = (new UserFactory($this->pdo))->create();

        $this->depot->record(
            userId: $utilisateur,
            action: 'artwork.update',
            entityType: 'artwork',
            entityId: 42,
            meta: ['title' => ['Ancien', 'Nouveau']],
            ipHash: str_repeat('a', 64),
            now: $this->instant('2026-07-21 09:30:00'),
        );

        $entrees = $this->depot->findRecent(10);

        $this->assertCount(1, $entrees);
        $this->assertSame('artwork.update', $entrees[0]['action']);
        $this->assertSame('artwork', $entrees[0]['entity_type']);
        $this->assertSame(42, $entrees[0]['entity_id']);
        $this->assertSame($utilisateur, $entrees[0]['user_id']);
    }

    public function test_le_differentiel_se_relit_tel_qu_il_a_ete_ecrit(): void
    {
        // Le differentiel sert a repondre « qu'est-ce qui a change ? » des mois
        // plus tard : s'il se relit deforme, il ne sert a rien.
        $this->depot->record(
            userId: null,
            action: 'category.update',
            entityType: 'category',
            entityId: 1,
            meta: ['title' => ['Encres', 'Encres & lavis'], 'is_published' => [false, true]],
            ipHash: null,
            now: $this->instant('2026-07-21 09:30:00'),
        );

        $entrees = $this->depot->findRecent(1);

        $this->assertSame(
            ['title' => ['Encres', 'Encres & lavis'], 'is_published' => [false, true]],
            $entrees[0]['meta'],
        );
    }

    public function test_une_action_anonyme_est_tracee_sans_acteur(): void
    {
        // Un echec de connexion n'a pas d'acteur identifie : l'adresse saisie
        // peut ne correspondre a aucun compte. La trace doit exister quand meme.
        $this->depot->record(
            userId: null,
            action: 'auth.login_failed',
            entityType: null,
            entityId: null,
            meta: null,
            ipHash: str_repeat('b', 64),
            now: $this->instant('2026-07-21 09:30:00'),
        );

        $entrees = $this->depot->findRecent(1);

        $this->assertNull($entrees[0]['user_id']);
        $this->assertNull($entrees[0]['meta']);
        $this->assertSame('auth.login_failed', $entrees[0]['action']);
    }

    public function test_les_entrees_sont_rendues_de_la_plus_recente_a_la_plus_ancienne(): void
    {
        foreach (['a', 'b', 'c'] as $index => $action) {
            $this->depot->record(
                userId: null,
                action: $action,
                entityType: null,
                entityId: null,
                meta: null,
                ipHash: null,
                now: $this->instant('2026-07-21 09:3' . $index . ':00'),
            );
        }

        $entrees = $this->depot->findRecent(10);

        $this->assertSame(['c', 'b', 'a'], array_column($entrees, 'action'));
    }

    public function test_la_liste_est_bornee(): void
    {
        foreach (range(1, 5) as $index) {
            $this->depot->record(
                userId: null,
                action: 'action.' . $index,
                entityType: null,
                entityId: null,
                meta: null,
                ipHash: null,
                now: $this->instant('2026-07-21 09:30:00'),
            );
        }

        $this->assertCount(2, $this->depot->findRecent(2));
        $this->assertCount(0, $this->depot->findRecent(0));
    }

    public function test_les_traces_d_une_entite_se_retrouvent(): void
    {
        // La fiche d'une œuvre affiche son historique : « qui a change quoi,
        // quand ». C'est l'index (entity_type, entity_id) qui le permet.
        $this->depot->record(null, 'artwork.update', 'artwork', 7, null, null, $this->instant('2026-07-21 09:30:00'));
        $this->depot->record(null, 'artwork.update', 'artwork', 8, null, null, $this->instant('2026-07-21 09:31:00'));
        $this->depot->record(null, 'artwork.publish', 'artwork', 7, null, null, $this->instant('2026-07-21 09:32:00'));

        $entrees = $this->depot->findForEntity('artwork', 7, 10);

        $this->assertCount(2, $entrees);
        $this->assertSame(['artwork.publish', 'artwork.update'], array_column($entrees, 'action'));
    }

    private function instant(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone('UTC'));
    }
}
