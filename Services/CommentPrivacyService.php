<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Services\Cms\PageCacheService;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\Blog\Models\Comment;
use Illuminate\Support\Facades\DB;

final class CommentPrivacyService
{
    /** @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function export(array $ids): array
    {
        return array_values(Comment::query()->whereIn('id', $ids)->orderBy('id')->get()->map(static fn (Comment $comment): array => $comment->toArray())->all());
    }

    /** @param list<int> $ids */
    public function erase(array $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            $selected = Comment::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            // The historical FK cascades replies. Preserve other people's contributions.
            Comment::query()->whereIn('parent_id', $selected)->whereNotIn('id', $selected)->update(['parent_id' => null]);
            $deleted = Comment::query()->whereIn('id', $selected)->delete();
            if ($deleted > 0) {
                app(PageCacheService::class)->invalidate();
                DB::afterCommit(static fn () => app(PageCacheService::class)->invalidate());
            }

            return $deleted;
        });
    }

    public function prune(): int
    {
        $policy = app(LegalPageService::class)->requireProcessing('blog_comments');
        $count = 0;
        Comment::query()->where('created_at', '<=', now()->subDays((int) $policy['retention_days']))->select('id')->chunkById(250, function ($comments) use (&$count): void {
            $count += $this->erase(array_values(array_map('intval', $comments->pluck('id')->all())));
        });

        return $count;
    }
}
