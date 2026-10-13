<?php

declare(strict_types=1);

use Access\Factory\ClaudeDriver;
use Access\Factory\FactoryStop;

function claudeDriver(): ClaudeDriver
{
    return new ClaudeDriver(30);
}

it('builds the claude command and resumes only with a session id', function () {
    $driver = claudeDriver();
    $base = [
        'claude',
        '-p',
        '--output-format',
        'stream-json',
        '--model',
        'sonnet',
        '--permission-mode',
        'bypassPermissions',
        '--permission-prompts',
        'none',
        'do the thing',
    ];

    expect($driver->command('/work', 'sonnet', 'do the thing', null))->toBe($base)
        ->and($driver->command('/work', 'sonnet', 'do the thing', ''))->toBe($base)
        ->and($driver->command('/work', 'sonnet', 'do the thing', 'chat-9'))->toBe([
            'claude',
            '-p',
            '--output-format',
            'stream-json',
            '--model',
            'sonnet',
            '--permission-mode',
            'bypassPermissions',
            '--permission-prompts',
            'none',
            '--resume',
            'chat-9',
            'do the thing',
        ]);
});

it('renders claude edit rules and denies its own settings tree', function () {
    $driver = claudeDriver();
    $author = $driver->sandboxConfig('spec_author');
    $implementer = $driver->sandboxConfig('implementer');

    expect($driver->name())->toBe('claude')
        ->and($driver->sandboxPath())->toBe('.claude/settings.json')
        ->and($author['permissions']['allow'])->toBe([])
        ->and($author['permissions']['deny'])->toContain('Edit(.specify/**)')
        ->and($author['permissions']['deny'])->toContain('Edit(.claude/**)')
        ->and($author['permissions']['deny'])->not->toContain('Edit(specs/**/spec.md)')
        ->and($implementer['permissions']['deny'])->toContain('Edit(specs/**/spec.md)');
});

it('parses a claude stream and ignores tool use blocks', function () {
    $reply = claudeDriver()->parse(<<<'JSON'
    {"type":"system","subtype":"init","session_id":"chat-1"}
    {"type":"assistant","message":{"content":[{"type":"text","text":"working"},{"type":"tool_use","name":"Edit"}]},"session_id":"chat-1"}
    {"type":"result","subtype":"success","is_error":false,"result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}
    JSON);

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1')
        ->and($reply->text)->not->toContain('tool_use');
});

it('uses assistant text when the claude result has no result string', function () {
    $reply = claudeDriver()->parse(<<<'JSON'
    {"type":"assistant","message":{"content":[{"type":"text","text":"{\"verdict\":\"approve\",\"issues\":[],\"assumptions\":[]}"}]},"session_id":"chat-2"}
    {"type":"result","subtype":"success","is_error":false,"session_id":"chat-2"}
    JSON);

    expect($reply->status)->toBe('approve')
        ->and($reply->chatId)->toBe('chat-2');
});

it('stops on a claude error result with no factory object', function () {
    expect(fn () => claudeDriver()->parse('{"type":"result","subtype":"error","is_error":true,"result":"API error","session_id":"chat-1"}'))
        ->toThrow(FactoryStop::class, 'API error');
});

it('keeps a factory object from a claude error result', function () {
    $reply = claudeDriver()->parse('{"type":"result","is_error":true,"result":"{\"status\":\"done\",\"summary\":\"ok\",\"assumptions\":[]}","session_id":"chat-1"}');

    expect($reply->status)->toBe('done')
        ->and($reply->chatId)->toBe('chat-1');
});

it('applies the claude exit policy', function () {
    $driver = claudeDriver();

    expect(fn () => $driver->outcome(1, '', 'claude broke'))
        ->toThrow(FactoryStop::class, 'claude broke');
    expect(fn () => $driver->outcome(1, 'still working', ''))
        ->toThrow(FactoryStop::class, 'still working');
    expect($driver->outcome(0, 'still working', '')->status)->toBe('unknown');
    expect($driver->outcome(1, '{"status":"done","summary":"ok","assumptions":[]}', 'noise')->status)->toBe('done');
});
