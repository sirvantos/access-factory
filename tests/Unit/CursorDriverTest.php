<?php

declare(strict_types=1);

use Access\Factory\CursorDriver;
use Access\Factory\FactoryStop;

function cursorDriver(): CursorDriver
{
    return new CursorDriver(30);
}

it('builds the cursor command and resumes only with a chat id', function () {
    $driver = cursorDriver();

    expect($driver->command('/work', 'composer-2', 'do the thing', null))->toBe([
        'cursor-agent',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'composer-2',
        '--trust',
        '--force',
        '--workspace',
        '/work',
        'do the thing',
    ])->and($driver->command('/work', 'composer-2', 'do the thing', ''))->toBe(
        $driver->command('/work', 'composer-2', 'do the thing', null),
    )->and($driver->command('/work', 'composer-2', 'do the thing', 'chat-9'))->toBe([
        'cursor-agent',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'composer-2',
        '--trust',
        '--force',
        '--workspace',
        '/work',
        '--resume',
        'chat-9',
        'do the thing',
    ]);
});

it('renders cursor write rules', function () {
    $driver = cursorDriver();
    $author = $driver->sandboxConfig('spec_author');
    $implementer = $driver->sandboxConfig('implementer');

    expect($driver->name())->toBe('cursor')
        ->and($driver->sandboxPath())->toBe('.cursor/cli.json')
        ->and($author['permissions']['allow'])->toBe([])
        ->and($author['permissions']['deny'])->toContain('Write(.specify/**)')
        ->and($author['permissions']['deny'])->not->toContain('Write(specs/**/spec.md)')
        ->and($implementer['permissions']['deny'])->toContain('Write(specs/**/spec.md)');
});

it('parses a cursor stream into a factory reply', function () {
    $reply = cursorDriver()->parse(<<<'JSON'
    {"type":"system","session_id":"chat-1"}
    {"type":"assistant","message":{"content":[{"text":"working"}]}}
    {"type":"result","result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
    JSON);

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1')
        ->and($reply->blocksApproval())->toBeFalse();
});

it('stops a failed cursor process that produced no factory object', function () {
    $driver = cursorDriver();

    expect(fn () => $driver->outcome(1, '', 'cursor broke'))
        ->toThrow(FactoryStop::class, 'cursor broke');

    expect(fn () => $driver->outcome(1, 'still working', ''))
        ->toThrow(FactoryStop::class, 'still working');
});

it('keeps a factory object from a non-zero cursor exit', function () {
    $reply = cursorDriver()->outcome(1, '{"status":"done","summary":"ok","assumptions":[]}', 'noise');

    expect($reply->status)->toBe('done');
});

it('returns unknown for a zero cursor exit with no factory object', function () {
    $reply = cursorDriver()->outcome(0, 'still working', '');

    expect($reply->status)->toBe('unknown');
});
