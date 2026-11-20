<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Http\Controllers\FrontPageController;
use DbCms\Modules\Blog\Http\Controllers\BlogFrontController;

it('keeps public content actions thin and delegates persistence and filtering', function (string $controller): void {
    $class = new ReflectionClass($controller);
    $source = file_get_contents($class->getFileName());
    expect($source)->not->toMatch('/(?:Post|Category|Comment|Market)::(?:query|create|where|with|published|resolveBySlugPath)/');
})->with([BlogFrontController::class, FrontPageController::class]);
