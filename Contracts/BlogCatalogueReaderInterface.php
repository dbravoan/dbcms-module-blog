<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Contracts;

use DbCms\Core\DTOs\Front\FrontPageData;
use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Filters\FilterSet;
use DbCms\Modules\Blog\Models\Category;

interface BlogCatalogueReaderInterface
{
    public function index(PublicContentContext $context): FrontPageData;

    public function filtered(PublicContentContext $context, FilterSet $set): FrontPageData;

    public function category(PublicContentContext $context, ?Category $category = null): FrontPageData;
}
