<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\Menu;
use DbCms\Core\Services\Cms\MenuService;
use Tests\Support\BlogComposition;
use Tests\Support\CmsFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es']);
});

test('module menu links use the configured localized module slug', function (): void {
    app(ModuleSettingsServiceInterface::class)->set('blog', 'slug_default', 'articulos');
    app(ModuleSettingsServiceInterface::class)->set('blog', 'slug_es', 'articulos');

    $menu = Menu::create(['slug' => 'header-links', 'location' => 'header', 'name' => 'Header', 'is_active' => true]);
    $item = $menu->items()->create(['type' => 'blog', 'is_active' => true, 'order' => 1]);
    $item->translations()->create(['locale' => 'es', 'title' => 'Artículos']);

    $links = app(MenuService::class)->getMenu('header', locale: 'es');

    expect($links)->toHaveCount(1)
        ->and($links[0]['url'])->toBe(url('/es/articulos'));
});
