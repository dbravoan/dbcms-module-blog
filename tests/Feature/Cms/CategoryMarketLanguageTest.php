<?php

declare(strict_types=1);

use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogComposition;
use Tests\Support\CmsFixtures;

uses(RefreshDatabase::class, BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es', 'us']);
    CmsFixtures::identities();
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
});

test('blog category stores dynamic translations and market assignment', function (): void {
    $markets = Market::query()->where('is_active', true)->take(2)->pluck('id')->all();

    $this->actingAs($this->admin, 'web')
        ->post(route('admin.blog.categories.store'), [
            'translations' => [
                'es' => ['name' => 'Categoría Dinámica', 'slug' => 'categoria-dinamica'],
                'en' => ['name' => 'Dynamic Category', 'slug' => 'dynamic-category'],
            ],
            'market_ids' => $markets,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.blog.categories.index'));

    $category = Category::query()
        ->whereHas('translations', fn ($query) => $query->where('slug', 'categoria-dinamica'))
        ->firstOrFail();

    expect($category->translations)->toHaveCount(2);
    expect($category->markets->pluck('id')->all())->toEqualCanonicalizing($markets);
});

test('category modals expose dynamic language tabs and market pickers', function (): void {
    $blog = $this->actingAs($this->admin, 'web')->get(route('admin.blog.categories.index'));
    $blog->assertOk();
    $blog->assertSee('cms-locale-switched', false);
    $blog->assertSee('market_ids[]', false);
    $blog->assertSee('searchSelect(', false);

});
