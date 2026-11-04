<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Contracts;

use DbCms\Core\DTOs\Front\FrontPageData;
use DbCms\Core\DTOs\Front\PublicContentContext;

interface BlogArticleReaderInterface
{
    public function show(PublicContentContext $context): FrontPageData;
}
