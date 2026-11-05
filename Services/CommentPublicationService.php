<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Contracts\Services\ModuleNotificationServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\DTOs\Front\PublicContentContext;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\Blog\Contracts\CommentPublicationServiceInterface;
use DbCms\Modules\Blog\DTOs\CommentOutcome;
use DbCms\Modules\Blog\DTOs\CommentSubmission;
use DbCms\Modules\Blog\Models\Comment;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommentPublicationService implements CommentPublicationServiceInterface
{
    public function __construct(private readonly LocalizationServiceInterface $localization, private readonly ModuleSettingsServiceInterface $settings, private readonly ModuleNotificationServiceInterface $notifications, private readonly ModuleManager $modules) {}

    public function submit(PublicContentContext $context, CommentSubmission $submission): CommentOutcome
    {
        if (! $this->modules->isModuleEnabled('blog')) {
            return new CommentOutcome(null);
        }

        return DB::transaction(function () use ($context, $submission): CommentOutcome {
            $post = Post::published()->forMarket($context->market)->whereHas('translations', fn ($query) => $query->where('slug', $context->slug)->where('locale', $context->locale))->with('translations')->lockForUpdate()->first();
            if ($post === null) {
                return new CommentOutcome(null);
            }
            $returnUrl = $this->localization->frontUrl('blog', $context->slug, $context->locale, $context->market);
            if (! $post->allowsComments()) {
                $message = 'Los comentarios están desactivados para este artículo.';

                return new CommentOutcome($returnUrl, ['error' => $message], ['comment_error' => $message]);
            }
            if ($submission->honeypotFilled) {
                return new CommentOutcome($returnUrl);
            }
            if ($submission->parentId !== null && ! Comment::query()->whereKey($submission->parentId)->where('post_id', $post->getKey())->where('is_approved', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'La respuesta debe corresponder a un comentario publicado de este artículo.']);
            }
            app(LegalPageService::class)->requireProcessing('blog_comments');
            $approved = ! (bool) $this->settings->get('blog', 'comments_require_approval', false);
            $comment = Comment::create(['post_id' => $post->getKey(), 'parent_id' => $submission->parentId, 'author_name' => $submission->authorName, 'author_email' => $submission->authorEmail, 'content' => $submission->content, 'is_approved' => $approved]);
            DB::afterCommit(function () use ($post, $comment): void {
                $this->notifications->notify(module: 'blog', type: 'info', title: 'Nuevo comentario en el blog', message: 'Comentario #'.$comment->getKey().'.', actionUrl: route('admin.blog.posts.edit', $post->getKey()), data: ['comment_id' => $comment->getKey(), 'post_id' => $post->getKey(), 'required_permission' => 'manage:posts']);
            });
            $message = $approved ? '¡Comentario publicado correctamente!' : 'Tu comentario ha sido enviado y se publicará tras ser revisado.';

            return new CommentOutcome($returnUrl, ['success' => $message, 'comment_success' => $message]);
        });
    }
}
