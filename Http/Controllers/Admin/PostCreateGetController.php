<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Modules\Blog\Models\Category;
use Illuminate\Contracts\View\View;

final class PostCreateGetController extends Controller
{
    public function __invoke(): View
    {
        $categories = Category::with('translations')->get();
        $markets = Market::with('languages')->where('is_active', true)->get();
        $languages = Language::where('is_active', true)->get();

        return view('admin.blog.posts.create', [
            'categories' => $categories,
            'markets' => $markets,
            'languages' => $languages,
        ]);
    }
}
