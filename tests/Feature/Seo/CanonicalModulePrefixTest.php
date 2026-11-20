<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CmsFixtures;
use Tests\Support\PublicContentComposition;

uses(RefreshDatabase::class, PublicContentComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es', 'us']);
    config(['cms.page_cache.enabled' => false]);
});

test('an alias module prefix 301s to the canonical localized prefix', function (): void {
    // Spanish market: `galeria` is canonical, `gallery` is the English alias.
    $this->get('/es/es/gallery')
        ->assertStatus(301)
        ->assertRedirect('/es/es/galeria');

    $this->get('/es/es/gallery')
        ->assertStatus(301)
        ->assertRedirect('/es/es/galeria');

    // The query string survives the consolidation.
    $this->get('/es/es/gallery?page=2')
        ->assertStatus(301)
        ->assertRedirect('/es/es/galeria?page=2');

    // Deep paths keep their tail.
    $this->get('/es/es/gallery/album-tail')
        ->assertStatus(301)
        ->assertRedirect('/es/es/galeria/album-tail');
});

test('the canonical prefix is served without redirecting', function (): void {
    $this->get('/es/es/galeria')->assertOk();
    $this->get('/es/es/blog')->assertOk();
    $this->get('/es/en/gallery')->assertOk();
    $this->get('/es/en/blog')->assertOk();
});

test('a slug that looks like another module prefix is never rewritten', function (): void {
    // `tienda` here is the second segment of a canonical blog path, not a
    // prefix: the request must reach the controller (404, unknown slug) instead
    // of being redirected to the shop.
    $response = $this->get('/es/es/blog/tienda');

    expect($response->getStatusCode())->toBe(404);
});

test('every alias resolves to its module key', function (): void {
    $aliases = app(LocalizationServiceInterface::class)->moduleAliases();

    expect($aliases)->toMatchArray([
        'galeria' => 'gallery',
        'gallery' => 'gallery',
        'blog' => 'blog',
    ])->not->toHaveKey('categoria');
});
