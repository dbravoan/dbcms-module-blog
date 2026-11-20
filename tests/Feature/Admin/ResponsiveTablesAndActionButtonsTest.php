<?php

declare(strict_types=1);

use DbCms\Core\Models\Menu;
use DbCms\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\Support\AdminCatalogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\FormFixtures;
use Tests\Support\GalleryFixtures;
use Tests\Support\PassportTestKeys;

uses(RefreshDatabase::class, AdminCatalogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es', 'mx']);
    CmsFixtures::identities();
    CmsFixtures::home();
    BlogFixtures::post();
    GalleryFixtures::album();
    FormFixtures::contact();
    PassportTestKeys::configure();
    Menu::create(['name' => 'Main', 'slug' => 'main', 'location' => 'header', 'is_active' => true]);
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();

    // Ensure at least 1 token exists for API tokens datatable
    app(ClientRepository::class)->createPersonalAccessGrantClient('Testing Client');
    $this->admin->createToken('Demo Responsive Test Token');
});

test('retained backoffice datatable views load successfully and contain responsive accordion and action button markers', function (string $url): void {
    $response = $this->actingAs($this->admin, 'web')->get($url);
    $response->assertOk();

    // Verify presence of responsive drawer toggle and accordion (stable marker,
    // decoupled from the Alpine expression).
    $response->assertSee('data-row-detail', false);
    $response->assertSee('fa-chevron-down');
    $response->assertSee('md:hidden');
})->with([
    'Menus' => '/admin/menus',
    'Pages' => '/admin/pages',
    'Posts' => '/admin/blog/posts',
    'Post Categories' => '/admin/blog/categories',
    'Users' => '/admin/users',
    'Markets' => '/admin/markets',
    'Forms' => '/admin/forms',
    'Galleries' => '/admin/galleries',
    'Tokens' => '/admin/tokens',
]);
