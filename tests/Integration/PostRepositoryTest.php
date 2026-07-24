<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Editorial\Post;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\PostRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\PostFactory;

#[CoversClass(PostRepository::class)]
#[CoversClass(Post::class)]
final class PostRepositoryTest extends DatabaseTestCase
{
    private function repository(): PostRepository
    {
        return new PostRepository($this->pdo);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_les_articles_publies_sortent_du_plus_recent_au_plus_ancien(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-02-01 09:00:00')
            ->translated('fr', 'ancien', 'Ancien')->create();
        (new PostFactory($this->pdo))->publishedAt('2026-06-01 09:00:00')
            ->translated('fr', 'recent', 'Récent')->create();

        $posts = $this->repository()->findPublished($this->now(), 10, 0);

        $this->assertCount(2, $posts);
        $this->assertSame('Récent', $posts[0]->title(Locale::Fr));
        $this->assertSame('Ancien', $posts[1]->title(Locale::Fr));
    }

    public function test_un_brouillon_n_est_pas_visible(): void
    {
        (new PostFactory($this->pdo))->draft()->translated('fr', 'brouillon', 'Brouillon')->create();

        $this->assertCount(0, $this->repository()->findPublished($this->now(), 10, 0));
    }

    public function test_un_article_programme_dans_le_futur_n_est_pas_encore_visible(): void
    {
        (new PostFactory($this->pdo))->published()->publishedAt('2026-12-01 09:00:00')
            ->translated('fr', 'a-venir', 'À venir')->create();

        $this->assertCount(0, $this->repository()->findPublished($this->now(), 10, 0));
    }

    public function test_le_comptage_ne_retient_que_les_articles_visibles(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-02-01 09:00:00')->translated('fr', 'un', 'Un')->create();
        (new PostFactory($this->pdo))->draft()->translated('fr', 'deux', 'Deux')->create();
        (new PostFactory($this->pdo))->publishedAt('2026-12-01 09:00:00')->translated('fr', 'trois', 'Trois')->create();

        $this->assertSame(1, $this->repository()->countPublished($this->now()));
    }

    public function test_un_article_est_retrouve_par_son_slug(): void
    {
        (new PostFactory($this->pdo))->publishedAt('2026-02-01 09:00:00')
            ->translated('fr', 'mon-expo', 'Mon exposition', 'Un aperçu', '<p>Corps.</p>')->create();

        $post = $this->repository()->findBySlug(Locale::Fr, Slug::fromString('mon-expo'), $this->now());

        $this->assertNotNull($post);
        $this->assertSame('Mon exposition', $post->title(Locale::Fr));
        $this->assertSame('<p>Corps.</p>', $post->body(Locale::Fr));
    }

    public function test_un_slug_inconnu_ne_renvoie_rien(): void
    {
        $this->assertNull($this->repository()->findBySlug(Locale::Fr, Slug::fromString('inexistant'), $this->now()));
    }

    public function test_un_brouillon_n_est_pas_accessible_par_son_slug(): void
    {
        (new PostFactory($this->pdo))->draft()->translated('fr', 'cache', 'Caché')->create();

        $this->assertNull($this->repository()->findBySlug(Locale::Fr, Slug::fromString('cache'), $this->now()));
    }

    public function test_le_repli_francais_sert_la_page_anglaise_sans_traduction(): void
    {
        // Article traduit en français seulement : /en/ le sert avec le slug FR
        // et le texte FR (05-i18n-seo §3), sans 404.
        (new PostFactory($this->pdo))->publishedAt('2026-02-01 09:00:00')
            ->translated('fr', 'sans-en', 'Sans anglais')->create();

        $post = $this->repository()->findBySlug(Locale::En, Slug::fromString('sans-en'), $this->now());

        $this->assertNotNull($post);
        $this->assertSame('Sans anglais', $post->title(Locale::En));
        $this->assertFalse($post->isTranslatedIn(Locale::En));
    }

    public function test_les_recents_sont_limites_et_ordonnes(): void
    {
        foreach (['2026-01-01', '2026-03-01', '2026-05-01', '2026-06-01'] as $i => $date) {
            (new PostFactory($this->pdo))->publishedAt($date . ' 09:00:00')
                ->translated('fr', 'a' . $i, 'Article ' . $i)->create();
        }

        $recents = $this->repository()->findRecent($this->now(), 3);

        $this->assertCount(3, $recents);
        $this->assertSame('Article 3', $recents[0]->title(Locale::Fr));
    }
}
