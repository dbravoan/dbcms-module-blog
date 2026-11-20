<?php

declare(strict_types=1);

use DbCms\Core\Cms;
use DbCms\Core\Content\Contracts\EntitySchemaRegistryInterface;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Post;
use Tests\Support\CmsFixtures;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
});

test('active modules register their entity schemas in the core registry', function (): void {
    $registry = app(EntitySchemaRegistryInterface::class);

    expect($registry->types())->toContain(
        'post',
        'blog_category',
        'page',
        'gallery',
        'form',
    );

    expect($registry->get('post')?->sectionable)->toBeTrue()
        ->and($registry->get('blog_category')?->sectionable)->toBeFalse()
        ->and($registry->get('post')?->fieldRoles())->toContain('title', 'summary', 'meta_title');
});

test('publishing a draft stamps its publish date so the front can resolve it', function (): void {
    $post = Post::create([
        'user_id' => $this->admin->id,
        'is_published' => false,
    ]);

    expect($post->published_at)->toBeNull();

    $post->update(['is_published' => true]);

    expect($post->fresh()->published_at)->not->toBeNull();
});

test('the fixed admin option enums live in config, not hardcoded in views', function (): void {
    expect(config('cms.options.columns'))->not->toBeEmpty()
        ->and(config('cms.options.code_languages'))->not->toBeEmpty()
        ->and(config('cms.options.schema_types'))->not->toBeEmpty()
        ->and(config('cms.options.menu_zones'))->not->toBeEmpty()
        ->and(config('cms.options.per_page'))->toBe([10, 15, 25, 50, 100]);

    $blockForm = (string) file_get_contents(Cms::path('resources/views/components/admin/blocks/_block_form_body.blade.php'));

    expect($blockForm)->not->toContain('admin.block_form.col_1')
        ->and($blockForm)->toContain('mountSchemaEditor(', 'data-schema-editor');
});
