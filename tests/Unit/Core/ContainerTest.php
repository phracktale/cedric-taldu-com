<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\Exception\ServiceNotRegistered;
use App\Core\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ControleurFactice;

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    public function test_un_service_enregistre_est_construit_a_la_demande(): void
    {
        $conteneur = new Container();
        $conteneur->set(SystemClock::class, static fn (): SystemClock => new SystemClock());

        $this->assertInstanceOf(SystemClock::class, $conteneur->get(SystemClock::class));
    }

    public function test_un_service_n_est_construit_qu_une_fois(): void
    {
        $conteneur = new Container();
        $appels = 0;
        $conteneur->set(SystemClock::class, static function () use (&$appels): SystemClock {
            $appels++;
            return new SystemClock();
        });

        $premier = $conteneur->get(SystemClock::class);
        $second = $conteneur->get(SystemClock::class);

        $this->assertSame($premier, $second);
        $this->assertSame(1, $appels);
    }

    public function test_une_fabrique_recoit_le_conteneur_pour_ses_dependances(): void
    {
        // Injection par constructeur, cablee a la main : aucune auto-decouverte,
        // aucune reflexion (src/CLAUDE.md).
        $conteneur = new Container();
        $conteneur->set(SystemClock::class, static fn (): SystemClock => new SystemClock());
        $conteneur->set(ControleurFactice::class, static function (Container $c): ControleurFactice {
            $c->get(SystemClock::class);
            return new ControleurFactice();
        });

        $this->assertInstanceOf(ControleurFactice::class, $conteneur->get(ControleurFactice::class));
    }

    public function test_un_service_inconnu_leve_une_exception(): void
    {
        $this->expectException(ServiceNotRegistered::class);

        (new Container())->get(SystemClock::class);
    }

    public function test_has_repond_sans_construire_le_service(): void
    {
        $conteneur = new Container();
        $construit = false;
        $conteneur->set(SystemClock::class, static function () use (&$construit): SystemClock {
            $construit = true;
            return new SystemClock();
        });

        $this->assertTrue($conteneur->has(SystemClock::class));
        $this->assertFalse($conteneur->has(ControleurFactice::class));
        $this->assertFalse($construit);
    }

    public function test_une_instance_deja_construite_peut_etre_fournie_directement(): void
    {
        $conteneur = new Container();
        $horloge = new SystemClock();

        $conteneur->instance(SystemClock::class, $horloge);

        $this->assertSame($horloge, $conteneur->get(SystemClock::class));
    }
}
