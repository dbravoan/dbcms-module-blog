<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Contracts;

use DbCms\Core\DTOs\Front\FrontPageData;
use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Modules\Blog\Models\Category;

interface BlogPublicReaderInterface
{
    public function index(PublicContentContext $context): FrontPageData;

    public function show(PublicContentContext $context): FrontPageData;

    public function category(PublicContentContext $context, ?Category $category = null): FrontPageData;

    public function resolveSlug(PublicContentContext $context): FrontPageData;
}
