<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockBindings;
use DbCms\Core\Blocks\BlockPatch;
use DbCms\Core\Models\Market;
use DbCms\Core\Sections\UnavailableBlockGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CmsFixtures;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    $this->market = Market::query()->where('code', 'es')->firstOrFail();
});

/** @return array<string,mixed> */
function opaqueColumnOwner(): array
{
    return ['id' => 'retired', 'type' => 'retired-owner', 'columns' => [['blocks' => [
        ['id' => 'hidden-binding', 'type' => 'heading', 'text' => 'Opaque value', 'bindings' => ['text' => ['source' => 'post', 'id' => 123456, 'field' => 'title']]],
    ]]]];
}

it('never validates bindings or queries public owners inside an unavailable nested column payload', function (): void {
    $payload = ['type' => 'accordion', 'items' => [['title' => 'Panel', 'children' => [opaqueColumnOwner()]]]];
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(BlockBindings::class)->validateTree($payload, $this->market, 'es');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'from `posts`')))->toBeEmpty();
});

it('preserves unavailable column owners and blocks their destructive ancestor patches', function (): void {
    $guard = app(UnavailableBlockGuard::class);
    $stored = ['id' => 'outer', 'type' => 'accordion', 'items' => [['title' => 'Panel', 'children' => [opaqueColumnOwner()]]]];
    expect($guard->containsUnavailable($stored))->toBeTrue();
    $incoming = ['id' => 'outer', 'type' => 'accordion', 'items' => [['title' => 'Panel', 'children' => []]]];
    expect($guard->reconcile($incoming, $stored)['items'][0]['children'])->toBe($stored['items'][0]['children']);
    expect(fn () => app(BlockPatch::class)->apply([$stored], [['op' => 'remove', 'node_id' => 'outer']]))->toThrow(ValidationException::class);
    $modified = $stored;
    $modified['items'][0]['children'][0]['columns'][0]['blocks'][0]['text'] = 'Changed opaque value';
    expect(fn () => $guard->reconcile($modified, $stored))->toThrow(ValidationException::class);
});
