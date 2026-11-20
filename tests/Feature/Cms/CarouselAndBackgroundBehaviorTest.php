<?php

declare(strict_types=1);

use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Blocks\Contracts\BlockTypeInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Sections\SectionLayout;
use DbCms\Core\Sections\SectionRendererService;
use DbCms\Core\Services\Content\PublishedSectionTextService;
use Tests\Support\VisualModulesComposition;

uses(VisualModulesComposition::class);

/**
 * Carousel block + background behaviour (parallax / fixed) on sections and
 * columns.
 *
 * The background controls live in the appearance modal's Section and Column
 * scopes, because those are the only places a background actually exists.
 */
test('the carousel block renders every slide with its controls', function (): void {
    $html = app(SectionRendererService::class)->renderBlockContent('carousel', [
        'type' => 'carousel',
        'autoplay' => true,
        'show_arrows' => true,
        'show_dots' => true,
        'items' => [
            ['label' => 'Primera diapositiva', 'children' => [['type' => 'image', 'image_url' => '/a.jpg'], ['type' => 'heading', 'text' => 'SLIDE UNO'], ['type' => 'rich_text', 'content' => 'Sub uno'], ['type' => 'button', 'label' => 'Ir', 'url' => '/x']]],
            ['label' => 'Segunda diapositiva', 'children' => [['type' => 'image', 'image_url' => '/b.jpg'], ['type' => 'heading', 'text' => 'SLIDE DOS']]],
        ],
    ], null, 'es');

    expect($html)->toContain('SLIDE UNO')
        ->toContain('SLIDE DOS')
        ->toContain('Sub uno')
        ->toContain('data-autoplay="true"')
        ->toContain('data-carousel-prev', 'data-carousel-next', 'data-carousel-pagination', 'role="region"');

    expect(substr_count($html, 'class="swiper-slide"'))->toBe(2);
});

test('the carousel can compose inside a column while catalog widgets retain their owned grid', function (): void {
    $registry = app(BlockRegistryInterface::class);

    $carousel = $registry->get('carousel');
    expect($carousel)->not->toBeNull()
        ->and($carousel?->isFullWidth())->toBeFalse();

    // Los widgets que ya gestionan su propia rejilla tampoco entran en columnas.
    foreach (['posts', 'gallery'] as $type) {
        $block = $registry->get($type);

        expect($block)->toBeInstanceOf(BlockTypeInterface::class);
        expect($block->isFullWidth())->toBeTrue("Block [{$type}] should be full width.");
    }
});

test('a section background can be pinned or parallaxed', function (): void {
    $renderer = app(SectionRendererService::class);

    $parallax = $renderer->renderSection([
        'id' => 0,
        'type' => 'rich_text',
        'styling_json' => [
            'bg_type' => 'image',
            'bg_image_url' => '/hero.jpg',
            'bg_behavior' => 'parallax',
            'content' => 'hola',
        ],
    ], null, 'es');

    expect($parallax)->toContain('data-scroll-scrub="parallax"')
        ->toContain('cms-bg-layer');

    $fixed = $renderer->renderSection([
        'id' => 0,
        'type' => 'rich_text',
        'styling_json' => [
            'bg_type' => 'image',
            'bg_image_url' => '/hero.jpg',
            'bg_behavior' => 'fixed',
            'content' => 'hola',
        ],
    ], null, 'es');

    expect($fixed)->toContain('background-attachment: fixed');
});

test('a column carries its own background and behaviour', function (): void {
    $html = app(SectionRendererService::class)->renderSection([
        'id' => 0,
        'type' => 'rich_text',
        'styling_json' => [
            'columns_layout' => '2',
            'columns' => [
                ['id' => 'c1', 'style' => ['bg_type' => 'image', 'bg_image_url' => '/col.jpg', 'bg_behavior' => 'parallax'], 'blocks' => [['type' => 'rich_text', 'title' => 'COL']]],
                ['id' => 'c2', 'blocks' => []],
            ],
        ],
    ], null, 'es');

    expect($html)->toContain('cms-column')
        ->toContain('cms-bg-layer')
        ->toContain('COL');
});

test('the column style is preserved by the canonical contract', function (): void {
    $payload = SectionLayout::normalize([
        'type' => 'rich_text',
        'columns_layout' => '2',
        'columns' => [
            ['id' => 'c1', 'style' => ['bg_color' => '#111', 'bg_behavior' => 'fixed'], 'blocks' => []],
            ['id' => 'c2', 'blocks' => []],
        ],
    ]);

    expect($payload['columns'][0]['style']['bg_behavior'])->toBe('fixed')
        ->and($payload['columns'][0]['style']['bg_color'])->toBe('#111');
});

test('carousel slide headlines reach the plain text surfaces', function (): void {
    $text = app(PublishedSectionTextService::class)->payloadText([
        'columns_layout' => '1',
        'columns' => [[
            'id' => 'c1',
            'blocks' => [[
                'type' => 'carousel',
                'items' => [
                    ['children' => [['type' => 'heading', 'text' => 'Primera diapositiva'], ['type' => 'rich_text', 'content' => 'Subtítulo uno']]],
                    ['children' => [['type' => 'heading', 'text' => 'Segunda diapositiva']]],
                ],
            ]],
        ]],
    ], new Market(['is_active' => true]), 'es');

    expect($text)->toContain('Primera diapositiva')
        ->toContain('Subtítulo uno')
        ->toContain('Segunda diapositiva');
});
