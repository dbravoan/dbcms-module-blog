<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Console;

use DbCms\Modules\Blog\Services\CommentPrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CommentPrivacyCommand extends Command
{
    protected $signature = 'blog:privacy {action : export, erase or prune} {--id=* : Explicit comment IDs} {--verified : Identity, scope and retention restrictions have been checked}';

    protected $description = 'Manage blog comment rights and configured retention, including inactive Blog data';

    public function handle(CommentPrivacyService $privacy): int
    {
        $action = $this->argument('action');
        if ($action === 'prune') {
            $this->info('Removed '.$privacy->prune().' expired comments.');

            return self::SUCCESS;
        }
        $ids = $this->option('id');
        if (! in_array($action, ['export', 'erase'], true) || ! $this->option('verified') || ! is_array($ids) || $ids === [] || count($ids) > 1000 || array_filter($ids, static fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) !== []) {
            $this->error('Use export or erase with explicit --id values and --verified after checking the request.');

            return self::FAILURE;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($action === 'erase') {
            $this->info('Erased '.$privacy->erase($ids).' comments.');

            return self::SUCCESS;
        }
        $path = 'privacy/blog-comments-'.Str::uuid().'.json';
        Storage::disk('local')->put($path, json_encode($privacy->export($ids), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'private');
        $this->info('Private export: '.Storage::disk('local')->path($path).'. Delete it after fulfilling the request.');

        return self::SUCCESS;
    }
}
