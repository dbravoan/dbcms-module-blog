<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Models\Comment;
use Illuminate\Http\RedirectResponse;

final class CommentApprovePostController extends Controller
{
    public function __invoke(Comment $comment): RedirectResponse
    {
        $comment->forceFill(['is_approved' => true])->save();

        return back()->with('success', 'Comentario aprobado y publicado.');
    }
}
