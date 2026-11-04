<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Requests;

use DbCms\Core\Forms\FormSchemas;
use DbCms\Modules\Blog\DTOs\CommentSubmission;
use Illuminate\Foundation\Http\FormRequest;

final class CommentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        if ($this->filled('_hp_security_check') || $this->filled('website_url')) {
            return [];
        }
        $schemas = app(FormSchemas::class);

        return $schemas->compileRules($schemas->blogComment()) + ['parent_id' => 'nullable|integer|min:1'];
    }

    public function submission(): CommentSubmission
    {
        $values = $this->validated();

        return new CommentSubmission((string) ($values['author_name'] ?? ''), (string) ($values['author_email'] ?? ''), (string) ($values['content'] ?? ''), isset($values['parent_id']) ? (int) $values['parent_id'] : null, $this->filled('_hp_security_check') || $this->filled('website_url'));
    }
}
