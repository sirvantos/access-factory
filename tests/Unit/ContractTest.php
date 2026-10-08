<?php

declare(strict_types=1);

use Access\Factory\Contract;
use Symfony\Component\Process\Process;

it('gives an agent eighty minutes', function () {
    $root = sys_get_temp_dir().'/access-factory-contract-'.bin2hex(random_bytes(4));
    mkdir($root.'/factory', 0777, true);
    file_put_contents($root.'/factory/factory.yaml', "agent_timeout_seconds: 4800\n");

    try {
        expect(Contract::load($root)->agentTimeout())->toBe(4800)
            ->and((new Contract([]))->agentTimeout())->toBe(4800);
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});
