<?php

declare(strict_types=1);

namespace Access\Factory;

interface FeatureScaffolder
{
    /**
     * @return array{branch: string, dir: string}
     */
    public function create(string $root, string $description): array;
}
