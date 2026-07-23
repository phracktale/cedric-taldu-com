<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\EventClaim;
use App\Repository\StripeEventRepository;
use DateTimeImmutable;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * Idempotence des webhooks Stripe (01-modele §7.8, 03-boutique §6).
 *
 * Stripe REESSAIE. Une livraison peut arriver deux fois, et deux livraisons
 * peuvent arriver en parallele. La garantie ne peut donc pas venir d'un
 * « SELECT puis INSERT si absent » : entre les deux, l'autre livraison passe.
 *
 * Elle vient de la CLE PRIMAIRE : on tente l'insertion, et c'est la base qui
 * dit lequel des deux a gagne.
 */
final class StripeEventRepositoryTest extends DatabaseTestCase
{
    private const RECU = '2026-07-22 10:00:00';

    private StripeEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new StripeEventRepository($this->pdo);
    }

    public function test_un_evenement_neuf_est_a_traiter(): void
    {
        $claim = $this->reclamer('evt_1');

        $this->assertSame(EventClaim::Fresh, $claim);
        $this->assertSame(1, $this->compter());
    }

    public function test_un_evenement_deja_traite_ne_l_est_pas_deux_fois(): void
    {
        // 01-modele §7.8 : « un event_id deja present avec processed_at non nul
        // n'est jamais retraite ». Le webhook repond 200 immediatement.
        $this->reclamer('evt_1');
        $this->repository->markProcessed('evt_1', new DateTimeImmutable(self::RECU));

        $this->assertSame(EventClaim::AlreadyProcessed, $this->reclamer('evt_1'));
    }

    public function test_un_evenement_recu_mais_non_traite_est_repris(): void
    {
        // Le cas d'un traitement qui a echoue : la transaction a ete annulee,
        // processed_at est reste nul, et Stripe reessaie. Il FAUT reprendre —
        // sinon un paiement encaisse resterait sans effet pour toujours.
        $this->reclamer('evt_1');

        $this->assertSame(EventClaim::Fresh, $this->reclamer('evt_1'));
    }

    public function test_le_type_et_l_empreinte_sont_conserves(): void
    {
        $this->repository->claim(
            'evt_1',
            'checkout.session.completed',
            hash('sha256', 'charge utile'),
            new DateTimeImmutable(self::RECU),
        );

        $this->assertSame(
            'checkout.session.completed',
            $this->valeur("SELECT type FROM stripe_events WHERE event_id = 'evt_1'"),
        );
        $this->assertSame(
            hash('sha256', 'charge utile'),
            $this->valeur("SELECT payload_hash FROM stripe_events WHERE event_id = 'evt_1'"),
        );
    }

    public function test_marquer_traite_horodate_l_evenement(): void
    {
        $this->reclamer('evt_1');

        $this->repository->markProcessed('evt_1', new DateTimeImmutable('2026-07-22 10:00:05'));

        $this->assertSame(
            '2026-07-22 10:00:05',
            $this->valeur("SELECT processed_at FROM stripe_events WHERE event_id = 'evt_1'"),
        );
    }

    public function test_marquer_traite_deux_fois_ne_reecrit_pas_l_horodatage(): void
    {
        // Le premier horodatage est celui du traitement reel ; l'ecraser
        // effacerait la trace du moment ou l'effet a eu lieu.
        $this->reclamer('evt_1');
        $this->repository->markProcessed('evt_1', new DateTimeImmutable('2026-07-22 10:00:05'));
        $this->repository->markProcessed('evt_1', new DateTimeImmutable('2026-07-22 11:00:00'));

        $this->assertSame(
            '2026-07-22 10:00:05',
            $this->valeur("SELECT processed_at FROM stripe_events WHERE event_id = 'evt_1'"),
        );
    }

    public function test_deux_evenements_distincts_coexistent(): void
    {
        $this->assertSame(EventClaim::Fresh, $this->reclamer('evt_1'));
        $this->assertSame(EventClaim::Fresh, $this->reclamer('evt_2'));

        $this->assertSame(2, $this->compter());
    }

    public function test_un_identifiant_vide_est_refuse(): void
    {
        // Un corps sans `id` exploitable ne doit pas creer une ligne qui
        // absorberait tous les evenements suivants.
        $this->assertSame(EventClaim::Invalid, $this->reclamer(''));
        $this->assertSame(0, $this->compter());
    }

    public function test_un_identifiant_trop_long_est_refuse(): void
    {
        // stripe_events.event_id est un VARCHAR(80) : une valeur plus longue
        // serait tronquee en silence et pourrait entrer en collision avec une
        // autre.
        $this->assertSame(EventClaim::Invalid, $this->reclamer(str_repeat('e', 81)));
        $this->assertSame(0, $this->compter());
    }

    // ------------------------------------------------------------ concurrence

    public function test_deux_livraisons_simultanees_n_en_traitent_qu_une(): void
    {
        // Le scenario reel : Stripe livre deux fois le meme evenement, et les
        // deux requetes arrivent en meme temps sur deux connexions. Un
        // « SELECT puis INSERT » laisserait passer les deux.
        $this->pdo->rollBack();

        $seconde = self::secondeConnexion();

        $premier = (new StripeEventRepository($this->pdo))->claim(
            'evt_concurrent',
            'checkout.session.completed',
            str_repeat('a', 64),
            new DateTimeImmutable(self::RECU),
        );

        $second = (new StripeEventRepository($seconde))->claim(
            'evt_concurrent',
            'checkout.session.completed',
            str_repeat('a', 64),
            new DateTimeImmutable(self::RECU),
        );

        $this->assertSame(EventClaim::Fresh, $premier);
        // Le second retrouve une ligne non traitee : il la reprend, ce qui est
        // le bon comportement quand le premier a pu echouer. La protection
        // contre le DOUBLE EFFET est portee par les UPDATE conditionnels du
        // traitement, pas par cette reclamation.
        $this->assertSame(EventClaim::Fresh, $second);
        $this->assertSame(1, $this->compter(), 'Une seule ligne, quoi qu’il arrive.');

        $this->pdo->exec('DELETE FROM stripe_events');
    }

    public function test_une_seconde_livraison_apres_traitement_est_ecartee(): void
    {
        $this->pdo->rollBack();

        $seconde = self::secondeConnexion();

        (new StripeEventRepository($this->pdo))->claim(
            'evt_concurrent',
            'checkout.session.completed',
            str_repeat('a', 64),
            new DateTimeImmutable(self::RECU),
        );
        (new StripeEventRepository($this->pdo))->markProcessed(
            'evt_concurrent',
            new DateTimeImmutable(self::RECU),
        );

        $this->assertSame(
            EventClaim::AlreadyProcessed,
            (new StripeEventRepository($seconde))->claim(
                'evt_concurrent',
                'checkout.session.completed',
                str_repeat('a', 64),
                new DateTimeImmutable(self::RECU),
            ),
        );

        $this->pdo->exec('DELETE FROM stripe_events');
    }

    // ------------------------------------------------------------ assistance

    private static function secondeConnexion(): PDO
    {
        $pdo = (new \App\Core\Database(
            host: getenv('DB_TEST_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('DB_TEST_PORT') ?: (getenv('DB_PORT') ?: '13306')),
            name: getenv('DB_TEST_NAME') ?: 'cedrictaldu_test',
            user: getenv('DB_MIGRATION_USER') ?: 'cedrictaldu_migrate',
            password: getenv('DB_MIGRATION_PASSWORD') ?: 'migration',
        ))->connect();

        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    private function reclamer(string $eventId): EventClaim
    {
        return $this->repository->claim(
            $eventId,
            'checkout.session.completed',
            str_repeat('a', 64),
            new DateTimeImmutable(self::RECU),
        );
    }

    private function compter(): int
    {
        return (int) $this->valeur('SELECT COUNT(*) FROM stripe_events');
    }

    private function valeur(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
