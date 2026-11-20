<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Contracts;

use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Modules\Blog\DTOs\CommentOutcome;
use DbCms\Modules\Blog\DTOs\CommentSubmission;

interface CommentPublicationServiceInterface
{
    public function submit(PublicContentContext $context, CommentSubmission $submission): CommentOutcome;
}
