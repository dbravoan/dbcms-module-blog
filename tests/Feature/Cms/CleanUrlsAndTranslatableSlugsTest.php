<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Sections\SectionRendererService;
use DbCms\Modules\Blog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\TestCase;

class CleanUrlsAndTranslatableSlugsTest extends TestCase
{
    use BlogComposition;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CmsFixtures::localization();
        CmsFixtures::identities();
        $post = BlogFixtures::post();
        $post->category->translations()->where('locale', 'es')->update(['slug' => 'tecnologia', 'name' => 'Tecnología']);
        $role = Role::where('slug', 'admin')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_blog_category_resolves_directly_without_categoria_prefix(): void
    {
        $category = Category::whereHas('translations', function ($q): void {
            $q->where('slug', 'tecnologia')->where('locale', 'es');
        })->firstOrFail();

        $response = $this->get('/es/es/blog/tecnologia');

        $response->assertOk();
        $response->assertSee('Tecnología', false);
        $response->assertSee('Inicio');
        $response->assertSee('Blog');
    }

    public function test_localization_service_returns_configurable_module_prefixes(): void
    {
        /** @var LocalizationServiceInterface $localization */
        $localization = app(LocalizationServiceInterface::class);

        // Default prefixes
        expect($localization->getModulePrefix('blog', 'es'))->toBe('blog');

        /** @var ModuleSettingsServiceInterface $moduleSettings */
        $moduleSettings = app(ModuleSettingsServiceInterface::class);
        $moduleSettings->set('blog', 'slug_es', 'noticias');

        expect($localization->getModulePrefix('blog', 'es'))->toBe('noticias');

        $allBlogSlugs = $localization->getAllModuleSlugs('blog');
        expect($allBlogSlugs)->toContain('noticias');

    }

    public function test_section_renderer_service_gracefully_silences_inactive_module_widgets(): void
    {
        /** @var SectionRendererService $renderer */
        $renderer = app(SectionRendererService::class);
        $market = Market::where('code', 'es')->first();

        // When blog is active, posts renders articles
        $htmlActive = $renderer->renderSection([
            'type' => 'posts',
            'title' => 'Artículos Destacados',
            'post_count' => 3,
        ], $market, 'es');

        expect($htmlActive)->toContain('Artículos Destacados');

        // Deactivate blog module in memory
        $moduleManager = app(ModuleManager::class);
        $moduleManager->deactivate('blog');

        // When blog is inactive, section renderer silences without 500 error
        $htmlInactive = $renderer->renderSection([
            'type' => 'posts',
            'title' => 'Artículos Destacados',
            'post_count' => 3,
        ], $market, 'es');

        expect($htmlInactive)->not->toContain('Artículos Destacados');
        expect(trim($htmlInactive))->toBe('');
    }
}
