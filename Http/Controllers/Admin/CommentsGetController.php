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
                ->paginate(20)->through(function (Comment $comment): Comment {
                    $translations = $comment->post?->translations;
                    $title = 'Artículo #'.$comment->post_id;
                    if ($translations !== null && $translations->isNotEmpty()) {
                        $title = ($translations->firstWhere('locale', app()->getLocale()) ?? $translations->first())->title;
                    }
                    $comment->setAttribute('admin_post_title', $title);

                    return $comment;
                }),
        ]);
    }
}
