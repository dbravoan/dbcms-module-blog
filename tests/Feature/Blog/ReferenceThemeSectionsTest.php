<?php

declare(strict_types=1);

use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\ThemePackageFixture;

uses(BlogComposition::class);

test('reference and independently installed Composer themes render published blog sections', function (bool $installed): void {
    $this->withoutVite();
    CmsFixtures::localization(['es'], ['es']);
    CmsFixtures::identities();
    $post = BlogFixtures::post(['es']);
    $section = $post->sections()->create(['type' => 'rich_text', 'status' => 'published', 'is_active' => true, 'order' => 0]);
    $section->translations()->create(['locale' => 'es', 'content_json' => ['type' => 'rich_text', 'content' => 'BLOG SECCION PUBLICADA']]);
    $packages = new ThemePackageFixture;
    $id = 'journey-blog-'.strtolower(str()->random(8));
    try {
        if ($installed) {
            $packages->front($id);
        }
        config(['cms.theme' => $installed ? $id : 'default-clean', 'cms.page_cache.enabled' => false]);
        $this->get('/es/es/blog/arquitectura-modular')->assertOk()->assertSee('BLOG SECCION PUBLICADA');
    } finally {
        $packages->restore();
    }
})->with([false, true]);
