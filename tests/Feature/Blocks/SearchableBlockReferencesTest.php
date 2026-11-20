<?php

declare(strict_types=1);

use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Services\Content\PublicTranslation;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Gallery\Models\Gallery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true]);
    $this->market = Market::create(['code' => 'ref', 'name' => 'Reference', 'default_locale' => 'en', 'currency' => 'USD', 'is_active' => true]);
    $this->market->languages()->sync([$english->id]);
    $this->author = User::factory()->create();
});

/** @return array{string,string,string,string} */
function referenceSourceDefinition(string $source): array
{
    return [
        'blog_posts' => ['posts', Post::class, 'title', 'id'],
        'blog_categories' => ['posts', Category::class, 'name', 'id'],
        'gallery_albums' => ['gallery', Gallery::class, 'title', 'slug'],
        'forms' => ['form', CustomForm::class, 'name', 'slug'],
    ][$source];
}

function searchableReferenceFixture(string $source, int $index, int $authorId): Model
{
    [, $class, $label] = referenceSourceDefinition($source);
    $attributes = match ($class) {
        Post::class => ['user_id' => $authorId, 'is_published' => true, 'published_at' => now()->subMinute()],
        Gallery::class => ['title' => 'Entry '.$index, 'slug' => 'entry-'.$index, 'is_active' => true],
        CustomForm::class => ['slug' => 'entry-'.$index, 'is_active' => true],
        default => ['is_active' => true],
    };
    $entity = $class::create($attributes);
    $translation = ['locale' => 'en', $label => 'Entry '.$index];
    if ($class !== CustomForm::class) {
        $translation['slug'] = 'entry-'.$index;
    }
    if ($class === Post::class) {
        $translation['content'] = 'Public content';
    }
    $entity->translations()->create($translation);

    return $entity;
}

it('paginates public choices and validates a selected reference outside the first page', function (string $source): void {
    [$type, , , $key] = referenceSourceDefinition($source);
    $block = app(BlockRegistryInterface::class)->get($type);
    for ($index = 1; $index <= 25; $index++) {
        $last = searchableReferenceFixture($source, $index, $this->author->id);
    }
    $first = $block->searchReferences($this->market, 'en', $source, '', 20, 0);
    $second = $block->searchReferences($this->market, 'en', $source, '', 20, 20);
    expect($first['items'])->toHaveCount(20)->and($first['has_more'])->toBeTrue()
        ->and($second['items'])->toHaveCount(5)->and($second['has_more'])->toBeFalse();
    $value = $key === 'id' ? $last->getKey() : 'entry-25';
    expect(array_column($first['items'], $key))->not->toContain($value);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($block->referenceExists($this->market, 'en', $source, $key, $value))->toBeTrue();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->contains(static fn (array $query): bool => str_contains(strtolower($query['query']), 'select exists')))->toBeTrue();
    expect($block->searchReferences($this->market, 'en', $source, '', 0, -3)['items'])->toHaveCount(1);
    expect(fn () => $block->referenceExists($this->market, 'en', $source, 'private_column', $value))->toThrow(ValidationException::class);
    expect(fn () => $block->searchReferences($this->market, 'en', 'private_source', '', 20, 0))->toThrow(ValidationException::class);
})->with(['blog_posts', 'blog_categories', 'gallery_albums', 'forms']);

it('searches effective regional labels and rejects unavailable references in every owner', function (string $source): void {
    [$type, $class, $label, $key] = referenceSourceDefinition($source);
    $block = app(BlockRegistryInterface::class)->get($type);
    $entity = searchableReferenceFixture($source, 1, $this->author->id);
    $regional = ['locale' => 'en', 'market_id' => $this->market->id, $label => 'Regional override'];
    if ($class !== CustomForm::class) {
        $regional['slug'] = 'regional-entry';
    }
    if (in_array('market_id', $entity->translations()->getRelated()->getFillable(), true)) {
        if ($class === Post::class) {
            $regional['content'] = 'Public regional content';
        }
        $entity->translations()->create($regional);
    } else {
        $entity->translations()->firstOrFail()->update([$label => 'Regional override']);
    }
    expect($block->searchReferences($this->market, 'en', $source, 'override', 20, 0)['items'])->toHaveCount(1)
        ->and($block->searchReferences($this->market, 'en', $source, 'Entry 1', 20, 0)['items'])->toBe([])
        ->and($block->searchReferences($this->market, 'fr', $source, '', 20, 0)['items'])->toBe([]);
    $selected = $key === 'id' ? $entity->getKey() : ($class === CustomForm::class ? 'entry-1' : (in_array('market_id', $entity->translations()->getRelated()->getFillable(), true) ? 'regional-entry' : 'entry-1'));
    expect($block->referenceExists($this->market, 'en', $source, $key, $selected))->toBeTrue();
    if ($class === Post::class) {
        $entity->update(['published_at' => now()->addDay()]);
    } else {
        $entity->update(['is_active' => false]);
    }
    expect($block->searchReferences($this->market, 'en', $source, '', 20, 0)['items'])->toBe([])
        ->and($block->referenceExists($this->market, 'en', $source, $key, $selected))->toBeFalse();
})->with(['blog_posts', 'blog_categories', 'gallery_albums', 'forms']);

it('caps queries at fifty and honors market assignment and disabled modules', function (): void {
    $block = app(BlockRegistryInterface::class)->get('form');
    for ($index = 1; $index <= 55; $index++) {
        searchableReferenceFixture('forms', $index, $this->author->id);
    }
    $page = $block->searchReferences($this->market, 'en', 'forms', '', 500, 0);
    expect($page['items'])->toHaveCount(50)->and($page['has_more'])->toBeTrue();
    $foreign = Market::create(['code' => 'foreign', 'name' => 'Foreign', 'default_locale' => 'en', 'currency' => 'USD', 'is_active' => true]);
    $form = CustomForm::firstOrFail();
    $form->markets()->sync([$foreign->id]);
    expect($block->referenceExists($this->market, 'en', 'forms', 'slug', $form->slug))->toBeFalse();
    CustomForm::query()->update(['is_active' => false]);
    expect($block->searchReferences($this->market, 'en', 'forms', '', 20, 0)['items'])->toBe([]);
});

it('constrains aliased SQL queries with the same blank regional fallback as the resolver', function (): void {
    $form = searchableReferenceFixture('forms', 1, $this->author->id);
    $form->translations()->create(['locale' => 'en', 'market_id' => $this->market->id, 'name' => '   ']);
    $query = CustomForm::query()->from('custom_forms as chosen');
    PublicTranslation::constrainResolvedField($query, $this->market, 'en', 'name', '=', 'Entry 1');
    expect($query->exists())->toBeTrue()
        ->and(PublicTranslation::resolve($form, 'en', $this->market)->getAttribute('name'))->toBe('Entry 1');
    expect(fn () => PublicTranslation::constrainResolvedField(CustomForm::query(), $this->market, 'en', 'password', '=', 'secret'))->toThrow(InvalidArgumentException::class);
    expect(fn () => PublicTranslation::constrainResolvedField(CustomForm::query(), $this->market, 'en', 'name', 'or 1=1', 'Entry 1'))->toThrow(InvalidArgumentException::class);
});
