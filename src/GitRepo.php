<?php

declare(strict_types=1);

namespace Access\Factory;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitRepo
{
    public function __construct(private string $root) {}

    public function branch(): string
    {
        return trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    public function ensureBranch(string $branch): void
    {
        if ($this->branch() === $branch) {
            return;
        }

        if ($this->branchExists($branch)) {
            $this->git(['checkout', $branch]);

            return;
        }

        $this->git(['checkout', '-b', $branch]);
    }

    /**
     * @return list<string>
     */
    public function changedFiles(string $cwd): array
    {
        $output = $this->git(['status', '--porcelain', '--untracked-files=all'], $cwd);
        $files = [];

        foreach (preg_split("/\r\n|\n|\r/", rtrim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $path = substr($line, 3);
            if (str_contains($path, ' -> ')) {
                $renamed = strrpos($path, ' -> ');
                $path = $renamed === false ? $path : substr($path, $renamed + 4);
            }

            $files[] = $path;
        }

        return $files;
    }

    public function worktreeAdd(string $path, string $branch, string $startPoint): void
    {
        if (is_dir($path)) {
            return;
        }

        $arguments = $this->branchExists($branch)
            ? ['worktree', 'add', $path, $branch]
            : ['worktree', 'add', '-b', $branch, $path, $startPoint];

        $this->git($arguments);
    }

    public function linkDependencies(string $worktree): void
    {
        foreach (['node_modules', '.env'] as $entry) {
            $source = $this->root.'/'.$entry;
            $target = $worktree.'/'.$entry;
            if (! file_exists($source) || file_exists($target)) {
                continue;
            }

            symlink($source, $target);
        }

        $this->installVendor($worktree);
    }

    public function writeJson(string $path, mixed $value): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create '.$directory.'.');
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('Could not write '.$path.'.');
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function commit(string $cwd, string $message, array $paths = []): void
    {
        if ($paths === []) {
            $this->git(['add', '-A'], $cwd);
        } else {
            $this->git(['add', '--', ...$paths], $cwd);
        }

        $staged = $this->process(['git', 'diff', '--cached', '--quiet'], $cwd);
        $staged->run();
        if ($staged->isSuccessful()) {
            return;
        }

        $this->git(['commit', '-m', $message], $cwd);
    }

    public function hasBranch(string $branch): bool
    {
        return $this->branchExists($branch);
    }

    public function pointBranch(string $branch, string $at): void
    {
        $this->git($this->branchExists($branch)
            ? ['branch', '-f', $branch, $at]
            : ['branch', $branch, $at]);
    }

    public function clearFeature(string $featureDir): void
    {
        $statePath = $this->root.'/'.$featureDir.'/.factory/state.json';
        $backup = is_file($statePath) ? (string) file_get_contents($statePath) : null;
        $checkout = $this->process(['git', 'checkout', 'HEAD', '--', $featureDir], $this->root);
        $checkout->run();

        if (is_dir($this->root.'/'.$featureDir)) {
            $this->git(['clean', '-fd', '--', $featureDir]);
        }

        if ($backup === null) {
            return;
        }

        $directory = dirname($statePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($statePath, $backup);
    }

    public function squashMerge(string $branch): void
    {
        $this->git(['merge', '--squash', $branch]);
    }

    public function removeWorktree(string $path, string $branch): void
    {
        if ($path !== '' && is_dir($path)) {
            $this->git(['worktree', 'remove', '--force', $path]);
        }

        if ($branch !== '' && $this->branchExists($branch)) {
            $this->git(['branch', '-D', $branch]);
        }
    }

    public function restore(string $cwd, string $path): void
    {
        $tracked = $this->process(['git', 'ls-files', '--error-unmatch', '--', $path], $cwd);
        $tracked->run();
        $absolute = $cwd.'/'.$path;

        if (! $tracked->isSuccessful()) {
            if (is_file($absolute)) {
                unlink($absolute);
            }

            return;
        }

        $this->git(['checkout', 'HEAD', '--', $path], $cwd);
    }

    public function discard(string $cwd): void
    {
        $this->git(['reset', '--hard', 'HEAD'], $cwd);
        $this->git(['clean', '-fd', '-e', '.env', '-e', 'vendor', '-e', 'node_modules'], $cwd);
    }

    private function installVendor(string $worktree): void
    {
        if (! is_file($worktree.'/composer.json') || ! is_file($worktree.'/composer.lock')) {
            return;
        }

        $vendor = $worktree.'/vendor';
        if (is_link($vendor)) {
            unlink($vendor);
        }

        $process = new Process(['composer', 'install', '--no-interaction', '--no-scripts', '--no-progress'], $worktree);
        $process->setTimeout(300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }
    }

    private function branchExists(string $branch): bool
    {
        $process = $this->process(['git', 'rev-parse', '--verify', '--quiet', $branch], $this->root);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, ?string $cwd = null): string
    {
        $process = $this->process(['git', ...$arguments], $cwd ?? $this->root);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, string $cwd): Process
    {
        return new Process($command, $cwd);
    }
}
