<?php

declare(strict_types=1);

/**
 * Phase 8 Plan 02 — pure-unit tests for ClaudeResponse::mapFinishReason +
 * default constants. Lives under tests/Unit/ so it runs without MySQL
 * (the integration-flavoured Prism::fake() round-trip lives in
 * tests/Feature/Agents/ClaudeClientTest.php and uses RefreshDatabase).
 */

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Integrations\Clients\ClaudeClient;

it('maps Prism FinishReason::Stop to local FinishReason::EndTurn', function () {
    expect(ClaudeResponse::mapFinishReason('Stop'))->toBe(FinishReason::EndTurn);
});

it('maps Prism FinishReason::ToolCalls to local FinishReason::ToolUse', function () {
    expect(ClaudeResponse::mapFinishReason('ToolCalls'))->toBe(FinishReason::ToolUse);
});

it('maps Prism FinishReason::Length to local FinishReason::MaxTokens', function () {
    expect(ClaudeResponse::mapFinishReason('Length'))->toBe(FinishReason::MaxTokens);
});

it('maps ContentFilter / Error / Other / Unknown / unrecognised case names to local FinishReason::Error', function () {
    expect(ClaudeResponse::mapFinishReason('ContentFilter'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Error'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Other'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Unknown'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('this-name-does-not-exist'))->toBe(FinishReason::Error);
});

it('exposes the AGNT-07 default constants', function () {
    expect(ClaudeClient::DEFAULT_MODEL)->toBe('claude-sonnet-4-6');
    expect(ClaudeClient::DEFAULT_MAX_STEPS)->toBe(8);
    expect(ClaudeClient::DEFAULT_MAX_TOKENS)->toBe(4000);
    expect(ClaudeClient::DEFAULT_TEMPERATURE)->toBe(0.0);
});
