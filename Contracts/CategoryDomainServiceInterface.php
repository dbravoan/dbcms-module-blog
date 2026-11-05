<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Contracts;

use DbCms\Core\DTOs\Admin\DatatableQueryData;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Category;

interface CategoryDomainServiceInterface
{
    /** @param array<string,mixed> $input */
    public function create(array $input, ?User $actor): Category;

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(Category $category, array $input): array;

    public function delete(Category $category): void;

    /** @return array<string,mixed> */
    public function indexData(DatatableQueryData $input): array;
}
