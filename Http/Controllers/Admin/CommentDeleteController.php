<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Models\Comment;
use Illuminate\Http\RedirectResponse;

final class CommentDeleteController extends Controller
{
    public function __invoke(Comment $comment): RedirectResponse
    {
        $comment->delete();

        return back()->with('success', 'Comentario eliminado.');
    }
}
