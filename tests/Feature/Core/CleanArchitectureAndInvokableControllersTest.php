<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use DbCms\Core\Contracts\Services\AdminNavigationServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsAdministrationInterface;
use DbCms\Core\Database\Seeders\CmsFoundationSeeder;
use DbCms\Core\DTOs\Admin\AdminNavigationItem;
use DbCms\Core\DTOs\Admin\AdminNavigationSection;
use DbCms\Core\Http\Controllers\Admin\ModuleSettings\ModuleSettingsGetController;
use DbCms\Core\Http\Controllers\Admin\ModuleSettings\ModuleSettingsPutController;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\ModuleAdministrationComposition;

uses(RefreshDatabase::class, ModuleAdministrationComposition::class);

beforeEach(function (): void {
    $this->seed(CmsFoundationSeeder::class);

    /** @var ModuleManager $manager */
    $manager = app(ModuleManager::class);
    $manager->discoverModules();

    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
    $this->adminUser = User::firstOrCreate(
        ['email' => 'admin@db-cms.io'],
        [
            'name' => 'Admin User',
            'password' => bcrypt('secret123'),
        ]
    );
    $this->adminUser->roles()->syncWithoutDetaching([$role->id]);

    Gate::before(fn () => true);
});

test('navigation service returns typed sections and items', function (): void {
    /** @var AdminNavigationServiceInterface $navService */
    $navService = app(AdminNavigationServiceInterface::class);

    $sections = $navService->getNavigationSections($this->adminUser);

    expect($sections)->not->toBeEmpty();
    expect($sections[0])->toBeInstanceOf(AdminNavigationSection::class);
    expect($sections[0]->title)->toBe('General');
    expect($sections[0]->items)->not->toBeEmpty();
    expect($sections[0]->items[0])->toBeInstanceOf(AdminNavigationItem::class);
});

test('deactivating module hides it from navigation identifiers and items', function (): void {
    /** @var ModuleManager $moduleManager */
    $moduleManager = app(ModuleManager::class);
    $moduleManager->discoverModules();

    $blogModule = CmsModule::where('identifier', 'blog')->firstOrFail();
    $moduleManager->deactivate('blog');
    $navService = app(AdminNavigationServiceInterface::class);
    expect($navService->getActiveModuleIdentifiers())->not->toContain('blog');
    $sections = $navService->getNavigationSections($this->adminUser);
    $routes = collect($sections)->flatMap(fn (AdminNavigationSection $section) => $section->items)->pluck('route')->all();
    expect($routes)->not->toContain('admin.blog.posts.index');
});

test('module settings get controller is invokable and renders view', function (): void {
    $response = $this->actingAs($this->adminUser, 'web')->get(route('admin.modules.settings', ['identifier' => 'blog']));

    $response->assertOk();
    expect(is_callable(new ModuleSettingsGetController(
        app(ModuleSettingsAdministrationInterface::class)
    )))->toBeTrue();
});

test('module settings put controller is invokable and updates settings', function (): void {
    expect(is_callable(new ModuleSettingsPutController(
        app(ModuleSettingsAdministrationInterface::class)
    )))->toBeTrue();

    $response = $this->actingAs($this->adminUser, 'web')->put(route('admin.modules.settings.update', ['identifier' => 'blog']), [
        'comments_enabled' => true,
    ]);

    $response->assertRedirect(route('admin.modules.settings', ['identifier' => 'blog']));
    $response->assertSessionHas('success');
});

test('ensure module enabled middleware aborts when module disabled', function (): void {
    /** @var ModuleManager $moduleManager */
    $moduleManager = app(ModuleManager::class);
    $moduleManager->discoverModules();

    CmsModule::where('identifier', 'blog')->update(['is_active' => false]);
    $moduleManager->deactivate('blog');

    $response = $this->actingAs($this->adminUser, 'web')->get('/admin/blog/posts');
    $response->assertNotFound();

    CmsModule::where('identifier', 'blog')->update(['is_active' => true]);
    $moduleManager->activate('blog');

    $responseActive = $this->actingAs($this->adminUser, 'web')->get('/admin/blog/posts');
    $responseActive->assertOk();
});
