<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * Phase 8 Plan 01 — Anthropic / Prism stop-reason mirror (D-06).
 *
 * Plan 02's ClaudeClient adapts Prism's response into this enum so consumers
 * never reach for vendor-specific strings. Pre-Plan 02 this enum is the
 * authoritative local source.
 *
 * Order is contract-stable — `AgentRunTest` asserts the case sequence.
 */
enum FinishReason: string
{
    case EndTurn = 'end_turn';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case StopSequence = 'stop_sequence';
    case Error = 'error';
}
