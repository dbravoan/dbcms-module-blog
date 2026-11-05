<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Capabilities\ActorContext;
use DbCms\Core\Capabilities\CapabilityAuthorizer;
use DbCms\Core\Capabilities\ExecutionContext;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\DTOs\Content\EntityMutationData;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Sections\SectionEditorService;
use DbCms\Core\Services\Capabilities\EntityMutationService;
use DbCms\Core\Services\Capabilities\EntityReadService;
use DbCms\Core\Services\Content\EditorialDraftService;
use DbCms\Core\Services\Seo\ContentQualityAuditorService;
use DbCms\Modules\Blog\Contracts\PostDomainServiceInterface;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class PostDomainService implements PostDomainServiceInterface
{
    public function __construct(private readonly EntityMutationService $mutations, private readonly EntityReadService $reader) {}

    /** @param array<string,mixed> $data */
    public function createPost(array $data, ?User $actor = null): Post
    {
        $actor ??= Auth::user();
        $actor ??= app(CapabilityAuthorizer::class)->user(app(ExecutionContext::class)->actor ?? new ActorContext(null));
        abort_unless($actor instanceof User, 403);
        $payload = $this->mutations->create('post', EntityMutationData::fromForm($data)->arguments, $actor);
        $post = $this->reader->resolveEntity('post', $payload['id']);
        assert($post instanceof Post);

        return $post->load(['translations', 'sections.translations', 'markets', 'category', 'author']);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updatePost(Post $post, array $data): array
    {
        $arguments = EntityMutationData::fromForm($data)->arguments;
        if ($post->is_published && ! empty($arguments['attributes']['is_published'])) {
            unset($arguments['attributes']['is_published'], $arguments['attributes']['published_at']);
        }

        return $this->mutations->update('post', $post->getKey(), $arguments);
    }

    public function deletePost(Post $post): void
    {
        $this->mutations->delete('post', $post->getKey());
    }

    /** @return array<string,mixed> */
    public function editData(Post $post): array
    {
        $post->load(['translations', 'sections.translations', 'markets']);
        $post = app(EditorialDraftService::class)->forEditor($post);
        assert($post instanceof Post);
        $data = [
            'post' => $post,
            'sectionsData' => app(SectionEditorService::class)->payload($post),
            'markets' => Market::with('languages')->where('is_active', true)->get(),
            'languages' => Language::where('is_active', true)->get(),
        ];
        $translation = $post->translations->firstWhere('locale', app(LocalizationServiceInterface::class)->defaultLocale()) ?? $post->translations->first();
        $data['categories'] = Category::with('translations')->get();
        $data['qualityReport'] = app(ContentQualityAuditorService::class)->audit((string) ($translation->title ?? ''), (string) ($translation->content ?? ''));

        return $data;
    }

    /**
     * Queries published posts scoped by market and locale.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function getFeed(?Market $market = null, ?string $locale = null, int $perPage = 12, ?int $page = null): LengthAwarePaginator
    {
        $locale = $locale ?? app()->getLocale();

        return Post::published()
            ->forMarket($market)
            ->whereHas('translations', function ($q) use ($locale): void {
                $q->where('locale', $locale);
            })
            ->with(['translations' => function ($q) use ($locale): void {
                $q->where('locale', $locale);
            }, 'author', 'category' => fn ($category) => $category->where('is_active', true)->forMarket($market)->with('translations')])
            ->latest('published_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
