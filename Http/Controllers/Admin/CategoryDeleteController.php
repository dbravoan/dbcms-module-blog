<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\CategoryDomainServiceInterface;
use DbCms\Modules\Blog\Models\Category;
use Illuminate\Http\RedirectResponse;

final class CategoryDeleteController extends Controller
{
    public function __construct(private readonly CategoryDomainServiceInterface $service) {}

    public function __invoke(Category $category): RedirectResponse
    {
        $this->service->delete($category);

        return redirect()->route('admin.blog.categories.index')->with('success', 'Categoría guardada correctamente.');
    }
}
