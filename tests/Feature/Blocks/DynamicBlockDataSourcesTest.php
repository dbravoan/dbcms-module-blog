<?php

declare(strict_types=1);

use DbCms\Core\Content\Contracts\EntitySchemaRegistryInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Blog\Services\Blocks\PostBlockDataSource;
use DbCms\Modules\Gallery\Models\Gallery;
use DbCms\Modules\Gallery\Services\Blocks\GalleryBlockDataSource;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    $language = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true]);
    $this->market = Market::create(['code' => 'source', 'name' => 'Source', 'default_locale' => 'en', 'currency' => 'USD', 'is_active' => true]);
    $this->market->languages()->sync([$language->id]);
    $this->author = User::factory()->create();
});

function blockSourceFixture(string $type, int $index, int $authorId): Model
{
    if ($type === 'post') {
        $model = Post::create(['user_id' => $authorId, 'is_published' => true, 'published_at' => now()->subMinutes($index), 'is_featured' => $index === 1, 'featured_image' => '/images/post.jpg']);
        $model->translations()->create(['locale' => 'en', 'title' => 'Article '.$index, 'slug' => 'entry-'.$index, 'summary' => '<b>Public summary</b>', 'content' => 'Private field sentinel']);
    } else {
        $model = Gallery::create(['title' => 'Gallery '.$index, 'slug' => 'entry-'.$index, 'is_active' => true]);
        $model->translations()->create(['locale' => 'en', 'title' => 'Gallery '.$index, 'slug' => 'entry-'.$index, 'description' => '<b>Public summary</b>']);
        $model->items()->create(['image_url' => '/images/gallery.jpg', 'title' => 'Cover', 'order' => 0]);
    }

    return $model;
}

it('owns a bounded public source with exact ID lookup and no model attribute exposure', function (string $type, string $handler): void {
    $source = app($handler);
    for ($index = 1; $index <= 55; $index++) {
        $last = blockSourceFixture($type, $index, $this->author->id);
    }
    $page = $source->items($this->market, 'en', ['order' => 'oldest'], 1, 500);
    expect($page['items'])->toHaveCount(50)->and($page['has_more'])->toBeTrue();
    expect(array_diff(array_keys($page['items'][0]), array_keys($source->fields())))->toBe([]);
    expect(array_keys($page['items'][0]))->not->toContain('cost_price', 'sku', 'user_id', 'stock_quantity', 'content', 'translations', 'created_at', 'is_active');
    expect($page['items'][0]['summary'])->toBe('Public summary');
    $selected = $source->items($this->market, 'en', ['id' => $last->id], 1, 20);
    expect($selected['items'])->toHaveCount(1)->and($selected['items'][0]['id'])->toBe($last->id)
        ->and($selected['items'][0]['url'])->toContain('/entry-55');
    expect($source->items($this->market, 'en', ['id' => 999999], 1, 20)['items'])->toBe([]);
    expect($source->items($this->market, 'en', [], 2, 50)['items'])->toHaveCount(5);
    expect(app(EntitySchemaRegistryInterface::class)->get($type)->publicContent->blockSource)->toBe($handler);
})->with([
    ['post', PostBlockDataSource::class], ['gallery', GalleryBlockDataSource::class],
]);

it('enforces publication, requested locale, market and module availability in every source', function (string $type, string $handler, string $owner): void {
    $source = app($handler);
    $record = blockSourceFixture($type, 1, $this->author->id);
    expect($source->items($this->market, 'fr', [], 1, 20)['items'])->toBe([]);
    $foreign = Market::create(['code' => 'other', 'name' => 'Other', 'default_locale' => 'en', 'currency' => 'EUR', 'is_active' => true]);
    $record->markets()->sync([$foreign->id]);
    expect($source->items($this->market, 'en', [], 1, 20)['items'])->toBe([]);
    $record->markets()->detach();
    if ($type === 'post') {
        $record->update(['published_at' => now()->addDay()]);
    } else {
        $record->update(['is_active' => false]);
    }
    expect($source->items($this->market, 'en', [], 1, 20)['items'])->toBe([]);
    if ($type === 'post') {
        $record->update(['published_at' => now()->subMinute()]);
    } else {
        $record->update(['is_active' => true]);
    }
    CmsModule::where('identifier', $owner)->update(['is_active' => false]);
    expect($source->items($this->market, 'en', [], 1, 20)['items'])->toBe([]);
})->with([
    ['post', PostBlockDataSource::class, 'blog'], ['gallery', GalleryBlockDataSource::class, 'gallery'],
]);
