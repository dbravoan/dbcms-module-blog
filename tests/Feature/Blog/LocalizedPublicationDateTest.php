<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\AdminThemeServiceInterface;
use DbCms\Core\Models\User;
use DbCms\Core\Services\Cms\ThemeManagerService;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    CmsFixtures::home();
    BlogFixtures::post()->update(['published_at' => '2026-11-04 12:00:00']);
});

test('blog dates use the public language', function (string $theme): void {
    app(ThemeManagerService::class)->setActiveTheme($theme);

    $this->get('/es/es/blog/arquitectura-modular')->assertOk()->assertSee('4 de noviembre de 2026');
    $this->get('/es/en/blog/arquitectura-modular')->assertOk()->assertSee('November 4, 2026');
})->with(['db-cyber', 'db-editorial', 'db-enterprise', 'db-minimal', 'db-sunset']);

test('configured adminer link is limited to the non-production panel', function (string $theme): void {
    config(['cms.adminer_url' => 'http://localhost:8099']);
    app(AdminThemeServiceInterface::class)->setActiveTheme($theme);
    $admin = User::where('email', 'admin@db-cms.io')->sole();

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('href="http://localhost:8099"', false);
    $this->get('/es/es/blog/arquitectura-modular')->assertOk()->assertDontSee('http://localhost:8099');
    config(['cms.adminer_url' => null]);
    $this->get('/admin')->assertOk()->assertDontSee('id="nav-adminer"', false);
})->with(['dbcms', 'termocms']);
