<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockCatalogService;
use DbCms\Core\Blocks\BlockEditorCatalogue;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Forms\Blocks\CustomFormBlock;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Blocks\BlogPostsBlock;
use DbCms\Modules\Blog\Models\Post;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    $language = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true]);
    $this->market = Market::firstOrCreate(['code' => 'us'], ['name' => 'United States', 'default_locale' => 'en', 'currency' => 'USD', 'is_default' => true, 'is_active' => true]);
    $this->market->languages()->sync([$language->getKey()]);
    $this->blocks = app(BlockRegistryInterface::class);
    config(['cms.theme' => 'db-cyber']);
});

test('posts renders published blog content', function (): void {
    $post = Post::create(['user_id' => User::factory()->create()->getKey(), 'is_published' => true, 'published_at' => now()->subMinute()]);
    $post->translations()->create(['locale' => 'en', 'title' => 'Public article', 'slug' => 'public-article', 'content' => 'Body']);
    $payload = ['selection' => 'latest', 'limit' => 1];

    $this->blocks->validate('posts', $payload);

    expect($this->blocks->render('posts', $payload, $this->market, 'en'))->toContain('Public article');
});

test('form renders the selected active form', function (): void {
    $form = CustomForm::create(['slug' => 'public-request', 'is_active' => true]);
    $form->translations()->create(['locale' => 'en', 'name' => 'Public request', 'submit_button_text' => 'Send request']);
    $payload = ['form_slug' => 'public-request'];

    $this->blocks->validate('form', $payload);

    expect($this->blocks->render('form', $payload, $this->market, 'en'))->toContain('Public request', 'Send request');
});

test('module blocks share their registered schema in the editor and agent catalogue', function (): void {
    $types = collect(app(BlockEditorCatalogue::class)->data()['availableTypesJs'])->keyBy('type');
    $catalog = app(BlockCatalogService::class)->catalog($this->market, 'en', includeReferences: false);

    foreach (['posts' => BlogPostsBlock::class, 'form' => CustomFormBlock::class] as $type => $class) {
        $block = $this->blocks->get($type);
        expect($block)->toBeInstanceOf($class)
            ->and($this->blocks->isAvailable($type))->toBeTrue()
            ->and($types[$type]['type'])->toBe($type)
            ->and($types[$type]['defaultPayload'])->toBe($block->getDefaultPayload())
            ->and($catalog[$type]['type'])->toBe($type)
            ->and($catalog[$type]['schema'])->toBe($block->getEditorSchema());
    }
});
