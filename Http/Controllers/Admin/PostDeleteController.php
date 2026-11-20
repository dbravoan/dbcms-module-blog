<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\PostDomainServiceInterface;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Http\RedirectResponse;

final class PostDeleteController extends Controller
{
    public function __construct(private readonly PostDomainServiceInterface $service) {}

    public function __invoke(Post $post): RedirectResponse
    {
        $this->service->deletePost($post);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Artículo eliminado correctamente.');
    }
}
