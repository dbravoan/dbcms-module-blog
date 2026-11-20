<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use Tests\Support\BlogComposition;
use Tests\Support\CmsFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
});

test('an explicitly cleared general setting stays empty instead of falling back', function (): void {
    $settings = app(GeneralSettingsServiceInterface::class);
    $settings->set('general.site_name', 'Custom name');

    expect($settings->get('general.site_name'))->toBe('Custom name');

    $settings->set('general.site_name', '');

    expect($settings->get('general.site_name'))->toBe('');
});

test('an explicitly cleared module setting stays empty instead of using the schema default', function (): void {
    $settings = app(ModuleSettingsServiceInterface::class);
    $settings->set('blog', 'slug_es', 'articulos');

    expect($settings->get('blog', 'slug_es'))->toBe('articulos');

    $settings->set('blog', 'slug_es', '');

    expect($settings->get('blog', 'slug_es'))->toBe('');
});
