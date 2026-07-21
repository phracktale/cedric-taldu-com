<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use App\Domain\Admin\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 04-back-office §1 : « Roles : `admin` (tout) et `editor` (contenu editorial et
 * catalogue, PAS les commandes, les reglages ni les utilisateurs). »
 *
 * La regle vit dans le domaine et non dans un `if` de controleur : c'est la
 * seule facon qu'un test la couvre exhaustivement, et qu'une route ajoutee au
 * lot 3 herite d'un refus par defaut plutot que d'un oubli.
 */
final class RoleTest extends TestCase
{
    public function test_un_administrateur_peut_tout_faire(): void
    {
        $role = Role::Admin;

        $this->assertTrue($role->canManageCatalog());
        $this->assertTrue($role->canManageOrders());
        $this->assertTrue($role->canManageSettings());
        $this->assertTrue($role->canManageUsers());
    }

    public function test_un_editeur_ne_touche_que_le_contenu(): void
    {
        $role = Role::Editor;

        $this->assertTrue($role->canManageCatalog());
        $this->assertFalse($role->canManageOrders());
        $this->assertFalse($role->canManageSettings());
        $this->assertFalse($role->canManageUsers());
    }

    #[DataProvider('rolesEtLibelles')]
    public function test_chaque_role_porte_un_libelle_francais(Role $role, string $attendu): void
    {
        $this->assertSame($attendu, $role->label());
    }

    /**
     * @return iterable<string, array{Role, string}>
     */
    public static function rolesEtLibelles(): iterable
    {
        yield 'administrateur' => [Role::Admin, 'Administrateur'];
        yield 'éditeur' => [Role::Editor, 'Éditeur'];
    }

    public function test_le_role_se_relit_depuis_la_valeur_stockee_en_base(): void
    {
        // La colonne est un ENUM('admin','editor') : les deux valeurs doivent se
        // relire, et rien d'autre.
        $this->assertSame(Role::Admin, Role::from('admin'));
        $this->assertSame(Role::Editor, Role::from('editor'));
        $this->assertNull(Role::tryFrom('superadmin'));
    }
}
