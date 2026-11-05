<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Content\Contracts\EditorialPreviewHandlerInterface;
use DbCms\Core\DTOs\Front\FrontPageData;
use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Sections\SectionRendererService;
use DbCms\Core\Services\Cms\FrontViewResolver;
use DbCms\Core\Services\Content\PublicTranslation;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Database\Eloquent\Model;

final class BlogEditorialPreview implements EditorialPreviewHandlerInterface
{
    public function render(Model $entity, PublicContentContext $context): FrontPageData
    {
        if (! $entity instanceof Post && ! $entity instanceof Category) {
            return FrontPageData::missing();
        }
        $translation = PublicTranslation::resolve($entity, $context->locale, $context->market);
        if ($translation === null) {
            return FrontPageData::missing();
        }
        if ($entity instanceof Category) {
            $posts = Post::published()->forMarket($context->market)
                ->where('category_id', $entity->getKey())
                ->whereHas('translations', fn ($query) => $query->where('locale', $context->locale))
                ->with(['translations', 'author', 'category.translations'])
                ->paginate(12, ['*'], 'page', $context->page);

            return FrontPageData::view(app(FrontViewResolver::class)->resolve('blog.category', 'blog::editorial-category-preview'), [
                'category' => $entity, 'translation' => $translation, 'posts' => $posts,
                'categories' => collect(), 'breadcrumbs' => [], 'hreflangs' => [],
                'alternateUrls' => [], 'currentMarket' => $context->market,
            ]);
        }
        $renderedSections = [];
        foreach ($entity->getRelation('sections')->where('is_active', true)->where('status', 'published')->sortBy('order') as $section) {
            $renderedSections[] = app(SectionRendererService::class)->renderSection($section, $context->market, $context->locale);
        }

        return FrontPageData::view(app(FrontViewResolver::class)->resolve('blog.show', 'blog::show'), [
            'post' => $entity,
            'translation' => $translation,
            'renderedSections' => $renderedSections,
            'hreflangs' => [],
            'alternateUrls' => [],
            'breadcrumbs' => [],
            'currentMarket' => $context->market,
            'currentLocale' => $context->locale,
        ]);
    }
}
