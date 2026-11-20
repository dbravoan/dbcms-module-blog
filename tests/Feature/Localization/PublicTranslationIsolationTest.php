<?php

declare(strict_types=1);

use DbCms\Modules\Blog\Models\Post;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es']);
    CmsFixtures::identities();
    CmsFixtures::home();
});

test('a public entity with no translation in the requested language is not served in another language', function (): void {
    BlogFixtures::post(['es']);

    $this->get('/es/blog/arquitectura-modular')->assertOk();
    $this->get('/en/blog/arquitectura-modular')->assertNotFound();

    $post = Post::query()->firstOrFail();
    expect($post->translations()->where('locale', 'en')->exists())->toBeFalse();
});

test('category slug paths never borrow another language', function (): void {
    BlogFixtures::post(['es']);
    $category = Post::query()->firstOrFail()->category;

    expect($category->getSlugPath('es'))->toBe('arquitectura')
        ->and($category->getSlugPath('en'))->toBe('');
});
