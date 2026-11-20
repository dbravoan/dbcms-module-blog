<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockBindings;
use DbCms\Core\Blocks\BlockPackageManager;
use DbCms\Core\Blocks\BlockRenderer;
use DbCms\Core\Blocks\BlockStyle;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    $language = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true]);
    $this->market = Market::create(['code' => 'runtime', 'name' => 'Runtime', 'default_locale' => 'en', 'currency' => 'USD', 'is_active' => true]);
    $this->market->languages()->sync([$language->id]);
    $this->post = Post::create(['user_id' => User::factory()->create()->id, 'is_published' => true, 'published_at' => now()]);
    $this->post->translations()->create(['locale' => 'en', 'title' => 'Public article', 'slug' => 'public-article', 'content' => 'Content']);
});

it('clears explicit withdrawn bindings without exposing stale data or querying the inactive owner', function (): void {
    $payload = ['type' => 'heading', 'text' => 'Stale fallback', 'bindings' => ['text' => ['source' => 'post', 'id' => $this->post->id, 'field' => 'title']]];
    app(BlockBindings::class)->validateTree($payload, $this->market, 'en');
    expect(app(BlockRenderer::class)->render('heading', $payload, $this->market, 'en'))->toContain('Public article');
    CmsModule::where('identifier', 'blog')->update(['is_active' => false]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $html = app(BlockRenderer::class)->render('heading', $payload, $this->market, 'en');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($html)->not->toContain('Public article', 'Stale fallback');
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'from `posts`')))->toBeEmpty();
});

it('validates exposed fields, scalar compatibility and binding keys before writes', function (): void {
    foreach ([
        ['type' => 'heading', 'bindings' => ['text' => ['source' => 'item', 'field' => 'title']]],
        ['type' => 'heading', 'bindings' => ['text' => ['source' => 'post', 'id' => $this->post->id, 'field' => 'user_id']]],
        ['type' => 'heading', 'bindings' => ['undeclared' => ['source' => 'post', 'id' => $this->post->id, 'field' => 'title']]],
        ['type' => 'heading', 'bindings' => ['text' => ['source' => 'post', 'id' => $this->post->id, 'field' => 'title', 'token' => 'unexpected']]],
        ['type' => 'heading', 'bindings' => ['text' => ['source' => 'post', 'id' => 999999, 'field' => 'title']]],
    ] as $invalid) {
        expect(fn () => app(BlockBindings::class)->validateTree($invalid, $this->market, 'en'))->toThrow(ValidationException::class);
    }
    expect(fn () => app(BlockRegistryInterface::class)->validate('heading', ['bindings' => ['text' => 'invalid']]))->toThrow(ValidationException::class);
});

it('sanitizes bound URL scalars from the current collection item', function (): void {
    $bindings = app(BlockBindings::class);
    $resolved = null;
    $bindings->withItem(['url' => 'javascript:alert(1)'], function () use ($bindings, &$resolved): string {
        $resolved = $bindings->resolve('button', ['bindings' => ['url' => ['source' => 'item', 'field' => 'url']]], $this->market, 'en');

        return '';
    });
    expect($resolved['url'])->toBe('');
});

it('caps repeated output bytes before concatenation and keeps responsive cascade independent of object order', function (): void {
    config(['cms.blocks.max_render_bytes' => 800]);
    $html = app(BlockRenderer::class)->children(array_fill(0, 30, ['type' => 'heading', 'text' => str_repeat('x', 100)]), $this->market, 'en');
    expect(strlen($html))->toBeLessThanOrEqual(800);
    $css = BlockStyle::stylesheet('#test', ['mobile' => ['color' => 'red'], 'focus' => ['color' => 'green'], 'tablet' => ['color' => 'blue'], 'base' => ['color' => 'black']]);
    expect(strpos($css, 'color:black'))->toBeLessThan(strpos($css, 'color:blue'))
        ->and(strpos($css, 'color:blue'))->toBeLessThan(strpos($css, 'color:red'))
        ->and(strpos($css, 'color:red'))->toBeLessThan(strpos($css, 'color:green'));
    $cssWithoutBase = BlockStyle::stylesheet('#test', ['mobile' => ['color' => 'red'], 'tablet' => ['color' => 'blue']]);
    expect(strpos($cssWithoutBase, 'color:blue'))->toBeLessThan(strpos($cssWithoutBase, 'color:red'));
});

it('shares one availability snapshot for a static render tree and refreshes the next root', function (): void {
    app(BlockPackageManager::class)->setEnabled('fixture_blocks', true);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $renderer = app(BlockRenderer::class);
    $html = $renderer->children(array_fill(0, 100, ['type' => 'fixture_chart', 'title' => 'Static node']), $this->market, 'en');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(substr_count($html, 'Static node'))->toBe(100);
    expect(count($queries))->toBeLessThanOrEqual(8);
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'from `block_packages`')))->toHaveCount(1);
    app(BlockPackageManager::class)->setEnabled('fixture_blocks', false);
    expect($renderer->children([['type' => 'fixture_chart', 'title' => 'Now unavailable']], $this->market, 'en'))->toBe('');
});
