<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;

final class FactoryStop extends RuntimeException
{
    public function __construct(
        public string $reason,
        string $detail,
    ) {
        parent::__construct($detail);
    }
}
