<?php

declare(strict_types=1);

namespace Access\Factory;

final class TaskItem
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id,
        public bool $done,
        public bool $parallel,
        public ?string $story,
        public string $description,
        public array $dependsOn,
        public string $phase,
        public string $source,
    ) {}
}
