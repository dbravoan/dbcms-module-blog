<?php

declare(strict_types=1);

use DbCms\Core\Content\Pages\Http\Controllers\Admin\PagePostController;
use DbCms\Core\Content\Pages\Http\Controllers\Admin\PagePutController;
use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostPostController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostPutController;
use DbCms\Modules\Blog\Models\Post;
use Tests\Support\ArchitectureBoundaryInspector;
use Tests\Support\ArchitectureSources;

test('editorial owners remain independent of optional audit and AI implementations', function (string $class): void {
    expect((new ArchitectureBoundaryInspector)->dependencyViolations(ArchitectureSources::forClass($class), ['DbCms\Modules\ContentAudit', 'DbCms\Modules\AiAssistant']))->toBe([]);
})->with([Page::class, PagePostController::class, PagePutController::class, Post::class, PostPostController::class, PostPutController::class]);
