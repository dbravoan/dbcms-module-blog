<?php

declare(strict_types=1);

use DbCms\Core\Http\Middleware\CheckPermission;
use DbCms\Modules\Blog\Http\Controllers\Admin\CategoriesGetController;
use DbCms\Modules\Blog\Http\Controllers\Admin\CategoryDeleteController;
use DbCms\Modules\Blog\Http\Controllers\Admin\CategoryPostController;
use DbCms\Modules\Blog\Http\Controllers\Admin\CategoryPutController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostCreateGetController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostDeleteController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostEditGetController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostPostController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostPutController;
use DbCms\Modules\Blog\Http\Controllers\Admin\PostsGetController;
use Illuminate\Support\Facades\Route;

Route::middleware([CheckPermission::class.':manage:posts'])->prefix('blog')->name('blog.')->group(function (): void {
    // Posts CRUD
    Route::get('/posts', PostsGetController::class)->name('posts.index');
    Route::get('/posts/create', PostCreateGetController::class)->name('posts.create');
    Route::post('/posts', PostPostController::class)->name('posts.store');
    Route::get('/posts/{post}/edit', PostEditGetController::class)->name('posts.edit');
    Route::put('/posts/{post}', PostPutController::class)->name('posts.update');
    Route::delete('/posts/{post}', PostDeleteController::class)->name('posts.destroy');

    // Categories
    Route::get('/categories', CategoriesGetController::class)->name('categories.index');
    Route::post('/categories', CategoryPostController::class)->name('categories.store');
    Route::put('/categories/{category}', CategoryPutController::class)->name('categories.update');
    Route::delete('/categories/{category}', CategoryDeleteController::class)->name('categories.destroy');
});
