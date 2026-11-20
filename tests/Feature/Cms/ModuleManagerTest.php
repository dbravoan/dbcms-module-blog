<?php

declare(strict_types=1);

use DbCms\Core\Database\Seeders\CmsFoundationSeeder;
use DbCms\Core\Filters\FilterModuleRegistry;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Search\Contracts\SearchSourceRegistryInterface;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CmsFixtures;
use Tests\Support\PublicContentComposition;

uses(RefreshDatabase::class, PublicContentComposition::class);

beforeEach(function (): void {
    $this->seed(CmsFoundationSeeder::class);
    $this->manager = app(ModuleManager::class);
    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password')]);
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->user->roles()->sync([$adminRole->id]);
});

test('it discovers installed Composer modules without changing their records', function (): void {
    $before = CmsModule::query()->orderBy('id')->get()->toArray();
    $discovered = $this->manager->discoverModules();

    expect($discovered)->toHaveKey('blog')
        ->and($discovered)->toHaveKey('gallery')
        ->and($discovered)->not->toHaveKey('forms')
        ->and($discovered)->not->toHaveKey('pages');

    expect(CmsModule::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('it toggles module activation status cleanly', function (): void {
    $this->manager->discoverModules();

    $this->manager->deactivate('blog');
    expect(CmsModule::where('identifier', 'blog')->value('is_active'))->toBeFalse();

    $this->manager->activate('blog');
    expect(CmsModule::where('identifier', 'blog')->value('is_active'))->toBeTrue();
});

test('backoffice displays all registered modules in marketplace UI', function (): void {
    $this->manager->discoverModules();

    $response = $this->actingAs($this->user, 'web')->get('/admin/modules');

    $response->assertStatus(200)
        ->assertSee(__('admin.page_titles.modules'))
        ->assertSee('Blog Engine')
        ->assertSee('Álbumes públicos')
        ->assertDontSee('Dynamic Form Builder');
});

test('deactivating a module disables its admin routes', function (): void {
    $this->manager->discoverModules();

    // Active module route works
    $this->actingAs($this->user, 'web')->get('/admin/blog/posts')->assertStatus(200);

    // Deactivate module
    $this->manager->deactivate('blog');

    // Route returns 404 because module is disabled
    $this->actingAs($this->user, 'web')->get('/admin/blog/posts')->assertStatus(404);
});

test('uninstallation preserves domain data and cannot be reversed by activation', function (): void {
    $this->manager->discoverModules();
    $post = Post::create(['user_id' => $this->user->id, 'is_published' => false]);
    $this->manager->uninstall('blog');
    expect($this->manager->isModuleEnabled('blog'))->toBeFalse()
        ->and($this->manager->activate('blog'))->toBeFalse()
        ->and($post->fresh())->not->toBeNull();
    $this->get('/es/es/blog')->assertNotFound();
});

test('disabled providers remain unavailable to filter and search consumers', function (): void {
    $this->manager->discoverModules();
    $filters = app(FilterModuleRegistry::class);
    $sources = app(SearchSourceRegistryInterface::class);
    expect($filters->get('blog'))->not->toBeNull()->and($sources->has('post'))->toBeTrue();
    $this->manager->deactivate('blog');
    expect($filters->get('blog'))->toBeNull()->and($sources->has('post'))->toBeFalse();
});

test('deactivation impact finds module blocks inside localized columns once per page', function (): void {
    $page = CmsFixtures::home();
    $section = $page->sections()->create(['type' => 'rich_text', 'order' => 1, 'is_active' => true, 'status' => 'published', 'styling_json' => ['type' => 'rich_text']]);
    foreach (['es', 'en'] as $locale) {
        $section->translations()->create(['locale' => $locale, 'content_json' => ['columns' => [[
            'id' => 'primary', 'blocks' => [['type' => 'accordion', 'items' => [['title' => 'Articles', 'children' => [['type' => 'posts']]]]]],
        ]]]]);
    }

    $impact = $this->manager->getDeactivationImpact('blog');

    expect($impact['affected_pages'])->toHaveCount(1)
        ->and($impact['affected_pages'][0]['id'])->toBe($page->getKey())
        ->and($impact['has_impact'])->toBeTrue();
});

test('explicit reconciliation refreshes shipped module metadata without changing installation choices', function (bool $installed, bool $active): void {
    $this->manager->discoverModules();
    $manifest = $this->manager->getModuleManifest('blog');
    $module = CmsModule::where('identifier', 'blog')->firstOrFail();
    $settings = ['is_enabled' => false, 'editorial_setting' => 'preserve'];
    $module->update([
        'name' => 'Old name', 'version' => '0.0.1', 'description' => 'Old description',
        'provider' => 'Old\\Provider', 'is_installed' => $installed,
        'is_active' => $active, 'settings' => $settings,
    ]);

    $this->manager->reconcileModules(['blog']);

    expect($module->fresh())->name->toBe($manifest['name'])
        ->version->toBe($manifest['version'])
        ->description->toBe($manifest['description'])
        ->provider->toBe($manifest['provider'])
        ->is_installed->toBe($installed)
        ->is_active->toBe($active)
        ->settings->toBe($settings);
})->with([[true, true], [true, false], [false, false]]);

test('deactivation impact detects owned public sources in compositions and scalar bindings', function (): void {
    $page = CmsFixtures::home();
    $post = Post::create(['user_id' => $this->user->id, 'is_published' => true, 'published_at' => now()]);
    $post->translations()->create(['locale' => 'es', 'title' => 'Public source', 'slug' => 'public-source', 'content' => 'Body']);
    $block = ['type' => 'heading', 'bindings' => ['text' => ['source' => 'post', 'id' => $post->id, 'field' => 'title']]];
    $section = $page->sections()->create(['type' => 'accordion', 'order' => 1, 'is_active' => true, 'status' => 'published', 'styling_json' => []]);
    $section->translations()->create(['locale' => 'es', 'content_json' => ['type' => 'accordion', 'items' => [['title' => 'Sources', 'children' => [$block]]]]]);
    $impact = $this->manager->getDeactivationImpact('blog');
    expect($impact['affected_pages'])->toHaveCount(1)
        ->and($impact['affected_pages'][0]['id'])->toBe($page->id)
        ->and($impact['has_impact'])->toBeTrue();
});
