<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Http\Requests;

use DbCms\Modules\Blog\Services\CategoryMutationRules;
use Illuminate\Foundation\Http\FormRequest;

final class CategoryMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return CategoryMutationRules::all();
    }
}
