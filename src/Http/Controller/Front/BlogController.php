<?php

declare(strict_types=1);

namespace App\Http\Controller\Front;

use App\Core\ClockInterface;
use App\Core\Exception\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Exception\InvalidSlug;
use App\Domain\Locale;
use App\Domain\Slug;
use App\Repository\MediaRepository;
use App\Repository\PostRepository;
use App\Service\I18n\UrlGenerator;
use App\Service\View\Chrome;

/**
 * Blog public « Actus » : liste paginée et article (02-front §6).
 *
 * La visibilité — publié ET daté au plus tard maintenant — est portée par le
 * dépôt ; le contrôleur lui passe l'heure courante et ne décide de rien. Un
 * article non visible renvoie 404, jamais 403 (06-securite §8, pas d'énumération).
 */
final class BlogController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly View $view,
        private readonly Chrome $chrome,
        private readonly PostRepository $posts,
        private readonly MediaRepository $medias,
        private readonly UrlGenerator $url,
        private readonly ClockInterface $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = self::locale($request);
        $now = $this->clock->now();

        $total = $this->posts->countPublished($now);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        // La page vient de l'URL : bornée aux pages qui existent, pour qu'un
        // « ?page=99999999 » ne déborde pas l'entier.
        $page = min(self::page($request->query('page')), $pages);

        $posts = $this->posts->findPublished($now, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('front/blog-list', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $locale === Locale::Fr ? 'Actus' : 'News',
            'posts' => $posts,
            'medias' => $this->coversFor($posts),
            'page' => $page,
            'pages' => $pages,
            'articleUrl' => fn (string $slug): string
                => $this->url->route('blog.show', ['locale' => $locale->value, 'slug' => $slug]),
        ], layout: 'layouts/public'));
    }

    public function show(Request $request): Response
    {
        $locale = self::locale($request);
        $slug = self::slug($request->attribute('slug'));

        $post = $this->posts->findBySlug($locale, $slug, $this->clock->now());

        if ($post === null) {
            throw new NotFoundException('Article introuvable.');
        }

        $cover = $post->coverMediaId === null
            ? null
            : ($this->medias->findByIds([$post->coverMediaId])[$post->coverMediaId] ?? null);

        return Response::html($this->view->render('front/blog-article', [
            ...$this->chrome->base($request, $locale),
            'metaTitle' => $post->title($locale),
            'post' => $post,
            'cover' => $cover,
            'listUrl' => $this->url->route('blog.index', ['locale' => $locale->value]),
        ], layout: 'layouts/public'));
    }

    /**
     * Couvertures des articles listés, indexées par identifiant de média.
     *
     * @param list<\App\Domain\Editorial\Post> $posts
     * @return array<int, \App\Domain\Catalog\Media>
     */
    private function coversFor(array $posts): array
    {
        $ids = [];

        foreach ($posts as $post) {
            if ($post->coverMediaId !== null) {
                $ids[] = $post->coverMediaId;
            }
        }

        return $ids === [] ? [] : $this->medias->findByIds($ids);
    }

    private static function slug(?string $value): Slug
    {
        try {
            return Slug::fromString($value ?? '');
        } catch (InvalidSlug) {
            // Un slug malformé ne désigne aucun article : 404, pas une erreur.
            throw new NotFoundException('Article introuvable.');
        }
    }

    private static function page(?string $value): int
    {
        if ($value === null || !ctype_digit($value) || strlen($value) > 6) {
            return 1;
        }

        return max(1, (int) $value);
    }

    private static function locale(Request $request): Locale
    {
        return Locale::fromString($request->attribute('locale') ?? Locale::reference()->value);
    }
}
