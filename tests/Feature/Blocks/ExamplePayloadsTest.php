<?php

declare(strict_types=1);

use DbCms\Core\Blocks\AbstractBlockType;
use DbCms\Core\Blocks\BlockEditorCatalogue;
use DbCms\Core\Blocks\BlockPackageManager;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Blocks\Contracts\BlockTypeInterface;
use DbCms\Core\Blocks\Contracts\TranslatableBlockInterface;
use DbCms\Core\Models\Market;
use Tests\Support\BlockModulesComposition;
use Tests\Support\CmsFixtures;

/*
 * «Con datos de ejemplo» must insert realistic, valid copy for every block in
 * every content language the editor offers (es and en).
 */

uses(BlockModulesComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization(['es', 'en'], ['es']);
    app(BlockPackageManager::class)->setEnabled('before_after', true);
});

/** @param array<mixed> $value
 * @return list<string> */
function exampleTexts(array $value): array
{
    $texts = [];
    array_walk_recursive($value, static function (mixed $item, int|string $key) use (&$texts): void {
        if (is_string($item) && trim($item) !== '' && ! in_array($key, ['type', 'format', 'level', 'variant', 'action', 'tone', 'fit', 'mode', 'selection', 'size', 'icon', 'chart_type', 'grid_columns', 'currency_symbol'], true)) {
            $texts[] = $item;
        }
    });

    return $texts;
}

function exampleBlock(string $type): BlockTypeInterface
{
    $block = app(BlockRegistryInterface::class)->get($type);
    expect($block)->not->toBeNull($type);

    return $block;
}

/** @return array<string, mixed> */
function examplePayloadFor(BlockTypeInterface $block, string $locale): array
{
    return $block instanceof AbstractBlockType ? $block->getExamplePayload($locale) : $block->getDefaultPayload();
}

$types = [
    // Native core blocks.
    'accordion', 'alert', 'button', 'carousel', 'code_embed', 'countdown', 'counter', 'drawer', 'heading', 'hotspots',
    'icon', 'image', 'list', 'lottie', 'map', 'playlist', 'price', 'progress', 'quote', 'rating', 'separator', 'spacer',
    'tabs', 'rich_text', 'video',
    // External Before/After and native charts.
    'before_after', 'charts', 'kpi', 'roi',
    // modules
    'posts', 'form', 'gallery', 'search_box',
];

test('the example payload is filled, keeps the schema keys and validates in es and en', function (string $type): void {
    $block = exampleBlock($type);
    $registry = app(BlockRegistryInterface::class);
    $schemaKeys = array_keys($block->getEditorSchema());

    foreach (['es', 'en'] as $locale) {
        $payload = examplePayloadFor($block, $locale);
        expect($payload)->not->toBeEmpty("{$type}:{$locale}")
            ->and(array_diff(array_keys($payload), $schemaKeys))->toBe([], "{$type}:{$locale} adds keys outside its schema")
            ->and(array_keys($payload))->toBe(array_keys($block->getDefaultPayload()), "{$type}:{$locale} keeps the default keys");
        if ($type !== 'spacer') {
            expect(exampleTexts($payload))->not->toBeEmpty("{$type}:{$locale} has no example copy");
        }
        // Required references (an existing form) are chosen by the editor, never invented.
        $reference = ['form' => 'form_slug'][$type] ?? null;
        if ($reference !== null) {
            expect($payload[$reference])->toBe('');
            $payload[$reference] = 'contacto';
        }
        $registry->validate($type, $payload);
    }
})->with($types);

test('translatable example copy is written in English for the en locale', function (string $type): void {
    $block = exampleBlock($type);
    expect($block)->toBeInstanceOf(TranslatableBlockInterface::class);
    $paths = $block->getTranslationPaths();
    $es = examplePayloadFor($block, 'es');
    $en = examplePayloadFor($block, 'en');
    // Symbols such as «%» or «+» are language neutral; only wording must change.
    $translated = array_filter($paths, static fn (string $path): bool => $path !== 'format' && ! str_ends_with($path, '.format') && preg_match('/\p{L}{2,}/u', (string) data_get($es, str_replace('.*.', '.0.', $path))) === 1);
    expect($translated)->not->toBeEmpty($type);
    foreach ($translated as $path) {
        $concrete = str_replace('.*.', '.0.', $path);
        expect(data_get($en, $concrete))->not->toBe(data_get($es, $concrete), "{$type}.{$concrete} is not translated");
    }
})->with(array_values(array_diff($types, ['spacer'])));

test('heading and button insert visible copy instead of empty fields', function (): void {
    expect(trim((string) exampleBlock('heading')->getDefaultPayload()['text']))->not->toBe('')
        ->and(trim((string) exampleBlock('button')->getDefaultPayload()['label']))->not->toBe('')
        ->and(trim((string) exampleBlock('button')->getDefaultPayload()['url']))->not->toBe('');
});

test('empty inserts keep empty schema defaults while examples stay separate', function (): void {
    $schema = exampleBlock('heading')->getEditorSchema();
    expect($schema['text']['default'] ?? null)->toBe('')
        ->and(exampleBlock('button')->getEditorSchema()['label']['default'] ?? null)->toBe('');
});

test('every basic example renders on the front office', function (string $type): void {
    $market = Market::query()->where('code', 'es')->firstOrFail();
    $html = app(BlockRegistryInterface::class)->render($type, examplePayloadFor(exampleBlock($type), 'es'), $market, 'es');
    expect(trim($html))->not->toBe('', $type);
})->with(['accordion', 'alert', 'button', 'carousel', 'counter', 'drawer', 'heading', 'hotspots', 'icon', 'image', 'list', 'map', 'price', 'progress', 'quote', 'rating', 'tabs', 'rich_text']);

test('the editor catalogue offers the example in each active content language', function (): void {
    $types = collect(app(BlockEditorCatalogue::class)->data()['availableTypesJs'])->keyBy('type');
    $heading = $types->get('heading');
    expect($heading['defaultPayloadByLocale']['es']['text'] ?? '')->toContain('Diseñamos')
        ->and($heading['defaultPayloadByLocale']['en']['text'] ?? '')->toContain('We design');
});
