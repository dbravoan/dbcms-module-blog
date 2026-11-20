<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Controllers;

use DbCms\Core\Http\Adapters\FrontPageResponseMapper;
use DbCms\Core\Http\Adapters\PublicContentContextFactory;
use DbCms\Core\Http\Controllers\Controller;
use DbCms\Modules\Blog\Contracts\BlogPublicReaderInterface;
use DbCms\Modules\Blog\Contracts\CommentPublicationServiceInterface;
use DbCms\Modules\Blog\Http\Adapters\CommentResponseMapper;
use DbCms\Modules\Blog\Http\Requests\CommentSubmissionRequest;
use DbCms\Modules\Blog\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BlogFrontController extends Controller
{
    public function __construct(private readonly BlogPublicReaderInterface $reader, private readonly PublicContentContextFactory $contexts, private readonly FrontPageResponseMapper $responses, private readonly CommentPublicationServiceInterface $comments, private readonly CommentResponseMapper $commentResponses) {}

    public function index(Request $request): View|RedirectResponse
    {
        return $this->responses->respond($this->reader->index($this->contexts->fromRequest($request)));
    }

    public function show(Request $request): View
    {
        return $this->responses->view($this->reader->show($this->contexts->fromRequest($request)));
    }

    public function resolveSlug(Request $request): View|RedirectResponse
    {
        return $this->responses->respond($this->reader->resolveSlug($this->contexts->fromRequest($request)));
    }

    public function category(Request $request, ?Category $category = null): View|RedirectResponse
    {
        return $this->responses->respond($this->reader->category($this->contexts->fromRequest($request), $category));
    }

    public function storeComment(CommentSubmissionRequest $request): RedirectResponse
    {
        return $this->commentResponses->respond($this->comments->submit($this->contexts->fromRequest($request), $request->submission()));
    }
}
