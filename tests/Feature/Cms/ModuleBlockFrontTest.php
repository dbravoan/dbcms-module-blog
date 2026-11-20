<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Services\PageDomainService;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Services\PostDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlogComposition;

uses(RefreshDatabase::class, BlogComposition::class);

test('a page with the posts block renders public posts on the front', function (): void {
    $spanish = Language::firstOrCreate(['code' => 'es'], ['name' => 'Spanish', 'native_name' => 'Español', 'is_default' => true, 'is_active' => true]);
    $market = Market::firstOrCreate(['code' => 'es'], ['name' => 'Spain', 'default_locale' => 'es', 'is_default' => true, 'is_active' => true]);
    $market->languages()->sync([$spanish->id => ['is_default' => true]]);
    $user = User::create(['name' => 'Editor', 'email' => 'posts-editor@example.com', 'password' => bcrypt('password')]);
    $user->roles()->attach(Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin'])->getKey());
    $this->actingAs($user);
    app(PostDomainService::class)->createPost([
        'user_id' => $user->id, 'market_ids' => [$market->id], 'is_featured' => true, 'is_published' => true, 'published_at' => now(),
        'translations' => ['es' => ['title' => 'Entrada pública', 'content' => 'Texto']],
    ]);
    app(PageDomainService::class)->createPage([
        'is_published' => true,
        'translations' => ['es' => ['title' => 'Noticias', 'slug' => 'noticias']],
        'market_ids' => [$market->id],
        'sections_json' => json_encode(['es' => [['type' => 'posts', 'selection' => 'featured', 'limit' => 3]]], JSON_THROW_ON_ERROR),
    ]);

    $this->get('/es/es/noticias')->assertOk()->assertSee('Entrada pública');
});
