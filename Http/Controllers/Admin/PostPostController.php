<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Core\Http\Requests\Admin\EditorialEntityRequest;
use DbCms\Modules\Blog\Contracts\PostDomainServiceInterface;
use Illuminate\Http\RedirectResponse;

final class PostPostController extends Controller
{
    public function __construct(private readonly PostDomainServiceInterface $service) {}

    public function __invoke(EditorialEntityRequest $request): RedirectResponse
    {
        $this->service->createPost($request->validated(), $request->user());

        return redirect()->route('admin.blog.posts.index')->with('success', 'Artículo creado correctamente.');
    }
}
