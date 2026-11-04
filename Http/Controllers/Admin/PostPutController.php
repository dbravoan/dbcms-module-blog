<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Core\Http\Requests\Admin\EditorialEntityRequest;
use DbCms\Modules\Blog\Contracts\PostDomainServiceInterface;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Http\RedirectResponse;

final class PostPutController extends Controller
{
    public function __construct(private readonly PostDomainServiceInterface $service) {}

    public function __invoke(EditorialEntityRequest $request, Post $post): RedirectResponse
    {
        $this->service->updatePost($post, $request->validated());

        return redirect()->route('admin.blog.posts.index')->with('success', 'Artículo actualizado correctamente.');
    }
}
