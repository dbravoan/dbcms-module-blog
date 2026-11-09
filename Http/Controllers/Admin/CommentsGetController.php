<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Models\Comment;
use Illuminate\Contracts\View\View;

final class CommentsGetController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.blog.comments.index', [
            'comments' => Comment::query()
                ->with(['post.translations'])
                ->orderBy('is_approved')
                ->latest()
                ->paginate(20),
        ]);
    }
}
