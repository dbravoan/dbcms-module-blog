<?php

declare(strict_types=1);

namespace Tests\Support;

/** Activates every module that ships its own block type. */
trait BlockModulesComposition
{
    public const TEST_MODULES = ['blog', 'gallery'];
}
