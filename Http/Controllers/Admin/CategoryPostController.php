<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\CategoryDomainServiceInterface;
use DbCms\Modules\Blog\Http\Requests\CategoryMutationRequest;
use Illuminate\Http\RedirectResponse;

final class CategoryPostController extends Controller
{
    public function __construct(private readonly CategoryDomainServiceInterface $service) {}

    public function __invoke(CategoryMutationRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());

        return redirect()->route('admin.blog.categories.index')->with('success', 'Categoría guardada correctamente.');
    }
}
