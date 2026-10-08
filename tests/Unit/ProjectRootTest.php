<?php

declare(strict_types=1);

use Access\Factory\ProjectRoot;
use Symfony\Component\Process\Process;

it('finds the directory that contains the factory contract', function () {
    $root = sys_get_temp_dir().'/access-factory-root-'.bin2hex(random_bytes(4));
    mkdir($root.'/factory/nested', 0777, true);
    file_put_contents($root.'/factory/factory.yaml', "mode: full\n");

    try {
        expect(ProjectRoot::find($root.'/factory/nested'))->toBe($root);
    } finally {
        $process = new Process(['rm', '-rf', $root]);
        $process->run();
    }
});

it('stops when no factory contract exists above the start directory', function () {
    ProjectRoot::find(sys_get_temp_dir());
})->throws(RuntimeException::class);
