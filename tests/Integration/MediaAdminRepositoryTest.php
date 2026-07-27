<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Admin\MediaAdminRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\MediaFactory;

/**
 * 04-back-office §7 : la mediatheque edite le copyright, une mention par image.
 */
final class MediaAdminRepositoryTest extends DatabaseTestCase
{
    private MediaAdminRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new MediaAdminRepository($this->pdo);
    }

    private function media(): MediaFactory
    {
        return new MediaFactory($this->pdo);
    }

    public function test_le_copyright_est_relu_avec_le_media(): void
    {
        $id = $this->media()->named('credit')->withCopyright('© Cédric Taldu')->create();

        $media = $this->repository->findById($id);

        $this->assertNotNull($media);
        $this->assertSame('© Cédric Taldu', $media['copyright']);
    }

    public function test_un_media_sans_copyright_est_relu_a_null(): void
    {
        $id = $this->media()->named('sans-credit')->create();

        $media = $this->repository->findById($id);

        $this->assertNotNull($media);
        $this->assertNull($media['copyright']);
    }

    public function test_le_copyright_est_mis_a_jour(): void
    {
        $id = $this->media()->named('maj')->create();

        $this->repository->updateCopyright($id, 'Photo : Jean Dupont');

        $media = $this->repository->findById($id);
        $this->assertNotNull($media);
        $this->assertSame('Photo : Jean Dupont', $media['copyright']);
    }

    public function test_un_copyright_vide_est_efface_a_null(): void
    {
        // Un champ laisse vide dans le formulaire ne doit pas persister une chaine
        // vide : le media est alors « sans credit », pas « credit = "" ».
        $id = $this->media()->named('efface')->withCopyright('© Ancien')->create();

        $this->repository->updateCopyright($id, null);

        $media = $this->repository->findById($id);
        $this->assertNotNull($media);
        $this->assertNull($media['copyright']);
    }
}
