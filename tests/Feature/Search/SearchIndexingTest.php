<?php

declare(strict_types=1);

use DbCms\Core\Models\SearchDocumentIndex;
use DbCms\Core\Search\Contracts\SearchSourceRegistryInterface;
use DbCms\Core\Search\SearchIndexer;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Blog\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;

uses(RefreshDatabase::class, BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    BlogFixtures::post();
    app(SearchIndexer::class)->flush();
});

test('every module exposes its search sources and entity schemas', function (): void {
    $registry = app(SearchSourceRegistryInterface::class);

    expect($registry->types())->toContain('post')
        ->and($registry->get('post')?->label('es'))->toBe('Artículo del blog')
        ->and($registry->get('post')?->label('en'))->toBe('Blog post');
});

test('the reindex command indexes published content per locale with canonical urls', function (): void {
    $this->artisan('search:reindex')->assertExitCode(0);

    $publishedPosts = Post::query()->where('is_published', true)->with('translations')->get();
    $expectedDocuments = $publishedPosts->sum(fn (Post $post): int => $post->translations->count());

    $post = $publishedPosts->firstOrFail();
    $locales = $post->translations->pluck('locale')->all();

    expect(SearchDocumentIndex::query()->where('type', 'post')->count())
        ->toBe($expectedDocuments);

    foreach ($post->translations as $translation) {
        $row = SearchDocumentIndex::query()
            ->where('type', 'post')
            ->where('entity_id', (string) $post->id)
            ->where('locale', $translation->locale)
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->title)->toBe($translation->title)
            ->and($row->url)->toContain('/blog/'.$translation->slug)
            ->and($row->url)->toContain('/'.$translation->locale)
            // Body text is stored plain (markup stripped) so excerpts never leak tags.
            ->and($row->body)->not->toContain('<');
    }
});

test('the reindex is fresh: unpublished content leaves the index', function (): void {
    $this->artisan('search:reindex')->assertExitCode(0);

    $before = SearchDocumentIndex::query()->where('type', 'post')->count();

    Post::query()->update(['is_published' => false]);

    $this->artisan('search:reindex')->assertExitCode(0);

    expect(SearchDocumentIndex::query()->where('type', 'post')->count())->toBe(0)
        ->and($before)->toBeGreaterThan(0);

    // Cover the --stats reporting path too.
    $this->artisan('search:reindex --stats')->assertExitCode(0);
});

test('a single entity can be reindexed after an edit', function (): void {
    $this->artisan('search:reindex')->assertExitCode(0);

    $translation = PostTranslation::query()->firstOrFail();
    $translation->update(['title' => 'Título actualizado para la búsqueda']);

    $indexed = app(SearchIndexer::class)->indexEntity('post', $translation->post_id);

    expect($indexed)->toBe(PostTranslation::query()->where('post_id', $translation->post_id)->count());

    $row = SearchDocumentIndex::query()
        ->where('type', 'post')
        ->where('entity_id', (string) $translation->post_id)
        ->where('locale', $translation->locale)
        ->firstOrFail();

    expect($row->title)->toBe('Título actualizado para la búsqueda');
});

test('reindexing a single entity removes it from the index when it stops being indexable', function (): void {
    $this->artisan('search:reindex')->assertExitCode(0);

    $post = Post::query()->firstOrFail();
    $post->update(['is_published' => false]);

    $indexed = app(SearchIndexer::class)->indexEntity('post', $post->id);

    expect($indexed)->toBe(0)
        ->and(SearchDocumentIndex::query()
            ->where('type', 'post')
            ->where('entity_id', (string) $post->id)
            ->count())->toBe(0);
});

test('a single type or locale can be reindexed', function (): void {
    $indexed = app(SearchIndexer::class)->indexSource('post', 'es');

    expect($indexed)->toBeGreaterThan(0)
        ->and(SearchDocumentIndex::query()->where('type', 'post')->where('locale', '!=', 'es')->count())->toBe(0)
        ->and(SearchDocumentIndex::query()->where('type', 'product')->count())->toBe(0);
});

test('editing or removing content keeps the index in sync automatically', function (): void {
    $this->artisan('search:reindex')->assertExitCode(0);

    $translation = PostTranslation::query()->firstOrFail();

    // A translation edit must update the index without running the command.
    $translation->update(['title' => 'Titulo sincronizado por evento']);

    expect(SearchDocumentIndex::query()
        ->where('type', 'post')
        ->where('entity_id', (string) $translation->post_id)
        ->where('locale', $translation->locale)
        ->value('title'))->toBe('Titulo sincronizado por evento');

    // Unpublishing removes the post from the index.
    $post = Post::query()->findOrFail($translation->post_id);
    $post->update(['is_published' => false]);

    expect(SearchDocumentIndex::query()
        ->where('type', 'post')
        ->where('entity_id', (string) $post->id)
        ->count())->toBe(0);

    // Deleting the post leaves nothing behind either.
    $post->update(['is_published' => true]);
    $post->delete();

    expect(SearchDocumentIndex::query()
        ->where('type', 'post')
        ->where('entity_id', (string) $post->id)
        ->count())->toBe(0);
});
