<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Contracts;

/**
 * Phase 8 channel feeds implement this (Google Merchant, Meta Catalog, Amazon, etc.).
 * Phase 1 ships the empty contract so later phases slot in without refactor.
 *
 * Downstream phases MUST extend this interface to add per-channel specifics; they
 * MUST NOT alter the three methods defined here (doing so breaks FOUND-13 contract).
 */
interface FeedGenerator
{
    /** Unique channel identifier — e.g. 'google_merchant', 'meta_catalog', 'amazon_atom'. */
    public function channel(): string;

    /** Generate the feed artifact. Returns absolute filesystem path or fully-qualified URL. */
    public function generate(): string;

    /** When this channel last produced a feed, or null if never generated. */
    public function lastGeneratedAt(): ?\DateTimeImmutable;
}
