<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\DTOs\Admin\DatatableQueryData;
use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\CategoryDomainServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CategoriesGetController extends Controller
{
    public function __construct(private readonly CategoryDomainServiceInterface $service) {}

    public function __invoke(Request $request): View
    {
        return view('admin.blog.categories.index', $this->service->indexData(DatatableQueryData::fromInput($request->query())));
    }
}
