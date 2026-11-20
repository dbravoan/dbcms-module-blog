<?php

declare(strict_types=1);

use DbCms\Core\Blocks\BlockPackageManager;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Sections\SectionRendererService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CmsFixtures;
use Tests\Support\VisualContentFixtures;
use Tests\Support\VisualModulesComposition;

uses(RefreshDatabase::class, VisualModulesComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    VisualContentFixtures::form();
    VisualContentFixtures::post();
    VisualContentFixtures::gallery();
    $this->admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
    $this->registry = app(BlockRegistryInterface::class);
    $this->renderer = app(SectionRendererService::class);
});

test('block registry registers the explicitly enabled static and dynamic block types', function (): void {
    app(BlockPackageManager::class)->setEnabled('before_after', true);
    $activeBlocks = $this->registry->getActiveBlocks();

    expect($activeBlocks)->toHaveKeys([
        'rich_text',
        'kpi',
        'price',
        'accordion',
        'quote',
        'rating',
        'list',
        'before_after',
        'code_embed',
        'roi',
        'posts',
        'charts',
        'form',
        'gallery',
    ]);
});

test('every active block type exposes a non-empty FontAwesome icon for pickers', function (): void {
    foreach ($this->registry->getActiveBlocks() as $type => $block) {
        $icon = (string) $block->getIcon();

        expect(trim($icon))->not->toBe('');
        expect($icon)->toContain('fa-');
    }
});

test('block registry dynamic data payloads provide all select entities', function (): void {
    $payloads = $this->registry->getDynamicDataPayloads();

    expect($payloads)->toHaveKeys([
        'forms',
        'blog_categories',
        'blog_posts',
        'gallery_albums',
    ]);

    expect($payloads['forms'])->not->toBeEmpty();
    expect($payloads['blog_categories'])->not->toBeEmpty();
    expect($this->registry->get('charts')->getEditorSchema()['chart_type']['options'])->toHaveKeys(['bar', 'line', 'donut', 'metric']);
});

test('section renderer renders decoupled roi block composing charts service', function (): void {
    $html = $this->renderer->renderSection([
        'type' => 'roi',
        'title' => 'Calculadora de Rentabilidad',
        'initial_investment' => 15000,
        'return_percentage' => 40,
        'months' => 12,
        'chart_mode' => 'bar',
        'content' => 'Nota metodológica sobre el ahorro en arquitectura perimetral.',
    ]);

    expect($html)->toContain('Calculadora de Rentabilidad');
    expect($html)->toContain('Retorno Neto Proyectado (ROI)');
    expect($html)->toContain('<svg'); // Verified that SVG chart was generated
});

test('section renderer renders feature grids and independently composed pricing and quote cards', function (): void {
    // 1. Features
    $featuresHtml = $this->renderer->renderSection([
        'type' => 'list',
        'items' => [
            ['text' => 'Alta Velocidad: menos de 20ms'],
            ['text' => 'Seguridad TLS: cifrado total'],
        ],
    ]);

    expect($featuresHtml)->toContain('Alta Velocidad: menos de 20ms', 'Seguridad TLS: cifrado total');

    $pricingHtml = $this->renderer->renderSection([
        'type' => 'accordion',
        'items' => [[
            'title' => 'Tarifas',
            'children' => [
                ['type' => 'heading', 'text' => 'Nuestras Tarifas'],
                ['type' => 'heading', 'text' => 'Pro Enterprise'],
                ['type' => 'price', 'amount' => '99', 'currency' => '€', 'period' => '/mes', 'note' => 'Top'],
                ['type' => 'list', 'items' => [['text' => 'SLA 99.9%']]],
                ['type' => 'button', 'label' => 'Comprar', 'url' => '/comprar'],
            ],
        ]],
    ]);
    expect($pricingHtml)->toContain('Nuestras Tarifas', 'Pro Enterprise', '99', '€', '/mes', 'SLA 99.9%', 'Comprar');
    $prices = new DOMDocument;
    @$prices->loadHTML('<meta charset="utf-8">'.$pricingHtml);
    $amount = (new DOMXPath($prices))->query('//*[contains(concat(" ", normalize-space(@class), " "), " cms-price-amount ")]')->item(0);
    expect(trim($amount->textContent))->toBe('99 €');

    $quoteHtml = $this->renderer->renderSection([
        'type' => 'accordion',
        'items' => [[
            'title' => 'Panel',
            'children' => [
                ['type' => 'heading', 'text' => 'Opiniones Reales'],
                ['type' => 'quote', 'author' => 'Sara Connor', 'source_label' => 'Tech Lead', 'quote' => 'Excelente solución modular.'],
                ['type' => 'rating', 'value' => 5, 'maximum' => 5, 'label' => 'Valoración del cliente'],
            ],
        ]],
    ]);
    expect($quoteHtml)->toContain('Opiniones Reales', 'Sara Connor', 'Excelente solución modular.', 'Tech Lead', '5 / 5');
    expect(substr_count($quoteHtml, 'cms-rating-filled'))->toBe(5);

});

test('section renderer renders multi-column layout with nested sub-blocks', function (): void {
    $multiColSection = [
        'columns_layout' => '2',
        'columns' => [
            [
                'id' => 'col_1',
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Columna izquierda'],
                ],
            ],
            [
                'id' => 'col_2',
                'blocks' => [
                    ['type' => 'form', 'title' => 'Formulario en Columna 2', 'form_slug' => 'contacto'],
                ],
            ],
        ],
    ];

    $html = $this->renderer->renderSection($multiColSection, Market::where('code', 'es')->firstOrFail(), 'es');

    expect($html)->toContain('cms-section-grid');
    expect($html)->toContain('Columna izquierda');
    expect($html)->toContain('Formulario en Columna 2');
    expect($html)->toContain('form');
});
