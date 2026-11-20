<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockEditorCatalogue;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Content\PublicSemanticRegistry;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Sections\UnavailableBlockGuard;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Gallery\Models\Gallery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    $language = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true]);
    $this->market = Market::firstOrCreate(['code' => 'us'], ['name' => 'United States', 'default_locale' => 'en', 'currency' => 'USD', 'is_default' => true, 'is_active' => true]);
    $this->market->languages()->sync([$language->getKey()]);
    $this->blocks = app(BlockRegistryInterface::class);
    $this->semantic = app(PublicSemanticRegistry::class);
    config(['cms.theme' => 'db-cyber']);
    app()->setLocale('es');
});

test('canonical blog selection shares publication ordering and featured filtering with semantics', function (): void {
    $author = User::factory()->create();
    foreach ([['Newest publication', now()->subMinute(), now()->subDays(4), false], ['Featured publication', now()->subDay(), now(), true]] as [$title, $published, $created, $featured]) {
        $post = Post::create(['user_id' => $author->getKey(), 'is_published' => true, 'published_at' => $published, 'is_featured' => $featured]);
        $post->forceFill(['created_at' => $created])->save();
        $post->translations()->create(['locale' => 'en', 'title' => $title, 'slug' => str($title)->slug()->toString(), 'content' => 'Public content']);
    }
    foreach (['latest' => 'Newest publication', 'featured' => 'Featured publication'] as $selection => $expected) {
        $payload = ['type' => 'posts', 'selection' => $selection, 'limit' => 1];
        $html = $this->blocks->render('posts', $payload, $this->market, 'en');
        $text = $this->semantic->widgetText($payload, $this->market, 'en');
        expect($html)->toContain($expected)->and($text)->toContain($expected);
        $other = $selection === 'latest' ? 'Featured publication' : 'Newest publication';
        expect($html)->not->toContain($other)->and($text)->not->toContain($other);
    }
});

test('canonical gallery keeps selected images and limits identical in HTML and semantics', function (): void {
    foreach (['default', 'exhibition'] as $slug) {
        $gallery = Gallery::create(['title' => $slug, 'slug' => $slug, 'is_active' => true]);
        $gallery->translations()->create(['locale' => 'en', 'title' => $slug, 'slug' => $slug]);
        foreach ([1, 2] as $order) {
            $gallery->items()->create(['title' => $slug.' image '.$order, 'image_url' => 'https://example.test/'.$slug.$order.'.jpg', 'order' => $order]);
        }
    }
    $payload = ['type' => 'gallery', 'album_slug' => 'exhibition', 'limit' => 1];
    foreach ([$this->blocks->render('gallery', $payload, $this->market, 'en'), $this->semantic->widgetText($payload, $this->market, 'en')] as $output) {
        expect($output)->toContain('exhibition image 1')->not->toContain('default image', 'exhibition image 2');
    }
    $payload['album_slug'] = 'absent';
    expect($this->semantic->widgetText($payload, $this->market, 'en'))->toBe('');
    expect($this->blocks->render('gallery', $payload, $this->market, 'en'))->not->toContain('default image', 'exhibition image');
});

test('canonical form uses its explicit locale and market for labels and submission URL', function (): void {
    Market::create(['code' => 'es', 'name' => 'Spain', 'default_locale' => 'es', 'currency' => 'EUR', 'is_active' => true]);
    $form = CustomForm::create(['slug' => 'request', 'is_active' => true]);
    $form->translations()->create(['locale' => 'en', 'name' => 'Request information', 'submit_button_text' => 'Send request']);
    $field = $form->fields()->create(['field_key' => 'name', 'type' => 'text', 'is_required' => true, 'order' => 0]);
    $field->translations()->create(['locale' => 'en', 'label' => 'Your name']);
    $html = $this->blocks->render('form', ['form_slug' => 'request'], $this->market, 'en');
    expect($html)->toContain('Request information', 'Send request', 'Your name', '/us/en/forms/request/submit');
});

test('retired variants remain unavailable and stored instances survive sibling changes', function (string $type): void {
    $stored = ['type' => 'text', 'columns' => [['id' => 'column', 'blocks' => [['id' => 'old', 'type' => $type, 'title' => 'Keep'], ['id' => 'current', 'type' => 'rich_text', 'content' => 'Before']]]]];
    $incoming = $stored;
    $incoming['columns'][0]['blocks'][1]['content'] = 'After';
    expect(app(UnavailableBlockGuard::class)->reconcile($incoming, $stored)['columns'][0]['blocks'][0])->toBe($stored['columns'][0]['blocks'][0]);
    expect($this->blocks->render($type, [], $this->market, 'en'))->toBe('');
    expect(fn () => $this->blocks->validate($type, []))->toThrow(ValidationException::class);
})->with(['blog.featured_posts', 'blog.latest_posts_grid', 'shop.featured_products', 'gallery.grid', 'forms.embed_form']);

test('editor schemas and catalogue avoid bulk choices while contextual search returns the selection', function (): void {
    $form = CustomForm::create(['slug' => 'editor-choice', 'is_active' => true]);
    $form->translations()->create(['locale' => 'en', 'name' => 'Editor choice']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach (['posts', 'gallery', 'form'] as $type) {
        $this->blocks->get($type)->getEditorSchema();
    }
    expect(DB::getQueryLog())->toBe([]);
    $catalogue = app(BlockEditorCatalogue::class)->data();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $definition = collect($catalogue['availableTypesJs'])->firstWhere('type', 'form');
    expect($definition['schema']['form_slug']['options_source'])->toBe('forms')
        ->and($definition['schema']['form_slug']['options'])->not->toHaveKey('editor-choice');
    $formSelections = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '`custom_forms`'));
    expect($formSelections)->toBeEmpty();
    $choices = $this->blocks->get('form')->searchReferences($this->market, 'en', 'forms', 'Editor choice', 1, 0);
    expect($choices['items'])->toHaveCount(1)
        ->and($choices['items'][0])->toMatchArray(['slug' => 'editor-choice', 'name' => 'Editor choice'])
        ->and($choices['has_more'])->toBeFalse();
    $form->update(['is_active' => false]);
    expect($this->blocks->getActiveBlocks())->toHaveKey('form');
    expect($this->blocks->get('form')->searchReferences($this->market, 'en', 'forms', 'Editor choice', 1, 0)['items'])->toBe([]);
    $form->update(['is_active' => true]);
    expect($this->blocks->getActiveBlocks())->toHaveKey('form');
});

test('editor category choices preserve selected content across translated slugs', function (string $type): void {
    $spanish = Language::firstOrCreate(['code' => 'es'], ['name' => 'Spanish', 'native_name' => 'Español', 'is_active' => true]);
    $this->market->languages()->syncWithoutDetaching([$spanish->getKey()]);
    $category = Category::create(['is_active' => true]);
    $category->translations()->createMany([
        ['locale' => 'es', 'name' => 'Categoría elegida', 'slug' => 'categoria-elegida'],
        ['locale' => 'en', 'name' => 'Chosen category', 'slug' => 'chosen-category'],
    ]);
    $entity = Post::create(['user_id' => User::factory()->create()->getKey(), 'category_id' => $category->getKey(), 'is_published' => true, 'published_at' => now()]);
    $entity->translations()->createMany([
        ['locale' => 'es', 'title' => 'Artículo elegido', 'slug' => 'articulo-elegido', 'content' => 'Contenido'],
        ['locale' => 'en', 'title' => 'Chosen article', 'slug' => 'chosen-article', 'content' => 'Content'],
    ]);
    $expected = ['es' => 'Artículo elegido', 'en' => 'Chosen article'];
    $catalogue = app(BlockEditorCatalogue::class)->data();
    $definition = collect($catalogue['availableTypesJs'])->firstWhere('type', $type);
    $source = 'blog_categories';
    expect($definition['schema']['category']['options_source'])->toBe($source)
        ->and($definition['schema']['category']['options'])->toBe(['' => 'Todas']);
    $choices = $this->blocks->get($type)->searchReferences($this->market, 'es', $source, 'elegida', 1, 0);
    expect($choices['items'])->toHaveCount(1)
        ->and($choices['has_more'])->toBeFalse();
    $selection = $choices['items'][0]['id'];
    $payload = ['type' => $type, 'category' => $selection, 'post_id' => null];
    $this->blocks->validate($type, $payload);
    foreach ($expected as $locale => $title) {
        expect($this->blocks->render($type, $payload, $this->market, $locale))->toContain($title);
        expect($this->semantic->widgetText($payload, $this->market, $locale))->toContain($title);
    }
    expect($selection)->toBe($category->getKey());
})->with(['posts']);
