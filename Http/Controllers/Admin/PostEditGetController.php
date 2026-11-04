<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers\Admin;

use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\PostDomainServiceInterface;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Contracts\View\View;

final class PostEditGetController extends Controller
{
    public function __construct(private readonly PostDomainServiceInterface $service) {}

    public function __invoke(Post $post): View
    {
        return view('admin.blog.posts.edit', $this->service->editData($post));
    }
}
