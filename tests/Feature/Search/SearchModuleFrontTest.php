<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\SearchDocumentIndex;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Search\Models\SearchTerm;
use DbCms\Core\Search\SearchIndexer;
use DbCms\Core\Search\Services\SearchSettings;
use DbCms\Core\Services\Cms\CoreFeatureSettings;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Blog\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\PrivacyFixtures;
use Tests\Support\SearchComposition;

uses(RefreshDatabase::class, SearchComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en', 'ca', 'fr'], ['es', 'us']);
    CmsFixtures::identities();
    BlogFixtures::post();
    // These parity cases explicitly include Catalan in the Spanish market.
    $catalan = Language::updateOrCreate(['code' => 'ca'], ['name' => 'Catalan', 'native_name' => 'Català', 'direction' => 'ltr', 'is_active' => true]);
    Market::where('code', 'es')->firstOrFail()->languages()->syncWithoutDetaching([$catalan->getKey() => ['is_default' => false]]);
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
    // Analytics throttling uses the default cache store, which must not leak
    // between tests.
    config(['cms.theme' => 'db-cyber', 'cms.admin_theme' => 'dbcms', 'cms.page_cache.enabled' => false, 'cache.default' => 'array']);

    app(SearchIndexer::class)->indexAll();
    $this->term = PostTranslation::query()->where('locale', 'es')->value('title');
});

test('the search hub renders in every locale with its own friendly prefix', function (): void {
    $this->get('/es/es/buscar')->assertOk()->assertSee('buscar', false);
    $this->get('/es/en/search')->assertOk();
    $this->get('/es/ca/cercar')->assertOk();

    // A non-canonical alias consolidates with a 301.
    $this->get('/es/es/search')
        ->assertStatus(301)
        ->assertRedirect('/es/es/buscar');
});

test('the search hub rejects an active locale not assigned to its requested market', function (): void {
    $french = Language::where('code', 'fr')->firstOrFail();
    $market = Market::where('code', 'es')->firstOrFail();
    $market->languages()->detach($french->getKey());
    expect($french->is_active)->toBeTrue();
    expect($market->languages()->whereKey($french->getKey())->exists())->toBeFalse();
    $this->get('/es/fr/recherche')->assertNotFound();
    $this->get('/es/es/buscar')->assertOk();
});

test('the search hub starts as noindex and the typed query consolidates into the url', function (): void {
    $response = $this->get('/es/es/buscar')->assertOk();

    expect((string) $response->getContent())->toContain('name="robots" content="noindex, follow"')
        ->toContain('name="q"');

    $this->get('/es/es/buscar?q=Arquitectura%20Modular')
        ->assertStatus(301)
        ->assertRedirect('/es/es/buscar/arquitectura-modular');
});

test('results are served on a canonical path with entity facets', function (): void {
    // A term that exists in two entity types, so the facet links appear.
    $indexer = app(SearchIndexer::class);
    $post = Post::published()->forMarket('es')->firstOrFail();
    $post->translations()->where('locale', 'es')->update(['title' => 'Termofacet demo']);
    $indexer->indexEntity('post', $post->id);
    $page = Page::create(['template' => 'default', 'layout' => 'app', 'is_published' => true, 'published_at' => now()]);
    $page->translations()->create(['locale' => 'es', 'title' => 'Termofacet demo', 'slug' => 'termofacet-page']);
    $page->markets()->attach(Market::where('code', 'es')->sole());
    $indexer->indexEntity('page', $page->id);

    $response = $this->get('/es/es/buscar/termofacet')->assertOk();
    $html = (string) $response->getContent();

    expect($html)
        ->toContain('Resultados para')
        ->toContain('SearchResultsPage')
        // Facets link to the typed canonical path, per entity type.
        ->toContain('/es/es/buscar/termofacet/post')
        ->toContain('/es/es/buscar/termofacet/page');

    // The type page renders only that entity type.
    $typed = (string) $this->get('/es/es/buscar/termofacet/post')->assertOk()->getContent();
    expect(substr_count($typed, 'fa-tag'))->toBe(1);

    // An unknown type is never served.
    $this->get('/es/es/buscar/termofacet/no-existe')->assertNotFound();

    // A non-canonical term form 301s to the slugged one.
    $this->get('/es/es/buscar/Termofacet%20Demo')
        ->assertStatus(301)
        ->assertRedirect('/es/es/buscar/termofacet-demo');
});

test('a search with no results suggests existing titles', function (): void {
    $response = $this->get('/es/es/buscar/zzz-arquitectura')->assertOk();

    expect((string) $response->getContent())->toContain('Quizá buscabas');
});

test('the inbox activates the search for Google, its sitemap entry and llms entry', function (): void {
    // Before activation: out of the sitemap and out of llms.txt.
    expect((string) $this->get('/es/es/sitemap.xml')->getContent())->not->toContain('/buscar');
    expect((string) $this->get('/es/es/llms.txt')->getContent())->not->toContain('/buscar');

    $this->actingAs($this->admin, 'web')
        ->post('/admin/seo/filters/search', ['action' => 'save', 'is_indexable' => '1'])
        ->assertRedirect();

    expect(app(SearchSettings::class)->isIndexable())->toBeTrue();

    // The hub leaves noindex…
    $hub = (string) $this->get('/es/es/buscar')->assertOk()->getContent();
    expect($hub)->toContain('name="robots" content="index, follow"');

    // …enters the sitemap and llms.txt…
    expect((string) $this->get('/es/es/sitemap.xml')->getContent())->toContain('/buscar');
    expect((string) $this->get('/es/es/llms.txt')->getContent())->toContain('/buscar');

    // …and a result page with enough content becomes indexable too.
    $indexer = app(SearchIndexer::class);

    foreach (range(1, 3) as $i) {
        $post = Post::withoutEvents(fn () => Post::create(['user_id' => $this->admin->getKey(), 'is_published' => true, 'published_at' => now()]));
        $post->translations()->create(['locale' => 'es', 'title' => 'Contenido suficiente '.$i, 'slug' => 'rich-'.$i, 'content' => 'Contenido público suficiente '.$i]);
        $post->markets()->attach(Market::where('code', 'es')->sole());
        $indexer->indexEntity('post', $post->id);
    }

    $rich = (string) $this->get('/es/es/buscar/contenido-suficiente')->assertOk()->getContent();
    expect($rich)->toContain('name="robots" content="index, follow"');

    // A result page with too little content stays noindex (thin content).
    $thin = (string) $this->get('/es/es/buscar/perplexity')->assertOk()->getContent();
    expect($thin)->toContain('noindex');

    // Flipping it back returns everything to noindex.
    $this->actingAs($this->admin, 'web')
        ->post('/admin/seo/filters/search', ['action' => 'save'])
        ->assertRedirect();

    expect(app(SearchSettings::class)->isIndexable())->toBeFalse();
    expect((string) $this->get('/es/es/buscar')->getContent())->toContain('noindex');
});

test('a thin result page stays noindex even when the search is activated', function (): void {
    app(CoreFeatureSettings::class)->set('search', 'is_indexable', true);
    app(CoreFeatureSettings::class)->set('search', 'min_results_to_index', '10');

    $thin = (string) $this->get('/es/es/buscar/arquitectura')->assertOk()->getContent();

    expect($thin)->toContain('noindex');
});

test('searches are recorded for the inbox, including the ones that find nothing', function (): void {
    $settings = app(GeneralSettingsServiceInterface::class);
    $processing = $settings->get('legal.processing', []);
    $policy = PrivacyFixtures::processingFor([])[0];
    $policy['owner'] = 'search_terms';
    $settings->set('legal.processing', [...$processing, $policy], 'legal', 'json');
    app(CoreFeatureSettings::class)->set('search', 'track_terms', true);
    $this->get('/es/es/buscar/arquitectura')->assertOk();
    $this->get('/es/es/buscar/zzz-nada')->assertOk();

    expect(SearchTerm::query()->where('term', 'arquitectura')->exists())->toBeTrue()
        ->and(SearchTerm::query()->where('term', 'zzz-nada')->value('zero_results'))->toBe(1);

    $inbox = $this->actingAs($this->admin, 'web')->get('/admin/seo/filters')->assertOk();

    $inbox->assertSee('arquitectura', false)
        ->assertSee('zzz-nada', false)
        ->assertSee('Reindexar ahora', false);
});

test('finishing without results still counts as a satisfied search when it later finds content', function (): void {
    $settings = app(GeneralSettingsServiceInterface::class);
    $processing = $settings->get('legal.processing', []);
    $policy = PrivacyFixtures::processingFor([])[0];
    $policy['owner'] = 'search_terms';
    $settings->set('legal.processing', [...$processing, $policy], 'legal', 'json');
    app(CoreFeatureSettings::class)->set('search', 'track_terms', true);
    $this->get('/es/es/buscar/arquitectura')->assertOk();
    $this->get('/es/es/buscar/arquitectura')->assertOk();

    // The second identical search is throttled, so counters stay honest.
    expect(SearchTerm::query()->where('term', 'arquitectura')->value('hits'))->toBe(1);
});

test('the inbox can rebuild the index on demand', function (): void {
    SearchDocumentIndex::query()->delete();

    $this->actingAs($this->admin, 'web')
        ->post('/admin/seo/filters/search', ['action' => 'reindex'])
        ->assertRedirect();

    expect(SearchDocumentIndex::query()->count())->toBeGreaterThan(0);
});

test('the search respects the configured results per page', function (): void {
    app(CoreFeatureSettings::class)->set('search', 'results_per_page', '1');

    $html = (string) $this->get('/es/es/buscar/arquitectura')->assertOk()->getContent();

    expect(substr_count($html, 'fa-tag'))->toBe(1);
});

test('the hub shows what can be found and the popular terms', function (): void {
    $settings = app(GeneralSettingsServiceInterface::class);
    $processing = $settings->get('legal.processing', []);
    $policy = PrivacyFixtures::processingFor([])[0];
    $policy['owner'] = 'search_terms';
    $settings->set('legal.processing', [...$processing, $policy], 'legal', 'json');
    app(CoreFeatureSettings::class)->set('search', 'track_terms', true);
    app(CoreFeatureSettings::class)->set('search', 'is_indexable', true);

    $this->get('/es/es/buscar/arquitectura')->assertOk();

    $hub = (string) $this->get('/es/es/buscar')->assertOk()->getContent();

    expect($hub)->toContain('Artículo del blog')
        ->toContain('arquitectura');
});

test('core search has localized routes without a module installation', function (): void {
    $market = Market::query()->where('code', 'es')->firstOrFail();

    expect(app(ModuleManager::class)->getModuleManifest('search'))->toBeNull()
        ->and(core_setting('search', 'slug_es'))->toBe('buscar')
        ->and(app(LocalizationServiceInterface::class)->getModulePrefix('search', 'en'))->toBe('search')
        ->and(app(LocalizationServiceInterface::class)->frontUrl('search', 'laravel', 'es', $market))
        ->toContain('/buscar/laravel');
});

test('core search does not record visitor queries by default', function (): void {
    $this->get('/es/es/buscar/privado')->assertOk();
    expect(app(SearchSettings::class)->tracksTerms())->toBeFalse()
        ->and(SearchTerm::query()->count())->toBe(0);
});
