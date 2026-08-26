<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260826-cpp — stop one competitor influencing prices, until a date.
 *
 * DRY-RUN BY DEFAULT (cross-cutting invariant) — --apply writes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS ACTUALLY FOR — AND WHAT IT IS NOT
 *
 * screenmoove stopped publishing on 2026-07-19 holding 176,132 of ~271,000
 * competitor price rows — 65% of everything. The pricing lookup only considers
 * rows inside the freshness window (30 days), so on 2026-08-18 every screenmoove
 * row aged out AT ONCE and every product whose only competitor was screenmoove
 * moved to cost-plus. The Screen International screens are exactly that: priced
 * 1p under screenmoove from 2026-06-01, orphaned last week.
 *
 * So pausing a SILENT competitor changes nothing today — age has already done
 * it, and this command says so rather than implying it helped.
 *
 * The pause earns its place at the other end: when the feed is REPAIRED, fresh
 * rows re-enter the window instantly and every affected price snaps back to
 * undercut with nobody reviewing it. A five-week-stale feed returning is
 * precisely when you want a beat to check the prices are sane first.
 *
 * WHY THE DATE IS MANDATORY
 *
 * Every temporary measure here that relied on being remembered is still in
 * place — the 260824-w9k overrides are a fortnight old. A dated pause expires
 * whether or not anyone comes back to it, so forgetting means the competitor
 * returns rather than staying invisible for good.
 *
 *   php artisan competitor:pause-pricing --competitor=screenmoove --until=2026-09-05
 *   php artisan competitor:pause-pricing --competitor=screenmoove --until=2026-09-05 --apply
 *   php artisan competitor:pause-pricing --list
 *   php artisan competitor:pause-pricing --competitor=screenmoove --resume --apply
 */
final class PauseCompetitorPricingCommand extends BaseCommand
{
    protected $signature = 'competitor:pause-pricing
        {--competitor= : Competitor name or id}
        {--until= : Date the pause expires (Y-m-d). Required to pause.}
        {--reason= : Why, for whoever reads this later}
        {--resume : Lift the pause now instead of setting one}
        {--list : Show current pauses and exit}
        {--apply : Write the change (default: dry-run, writes nothing)}';

    protected $description = 'Pause one competitor from influencing prices until a date, for a broken or stale feed (260826-cpp). Dry-run by default.';

    protected function perform(): int
    {
        if ((bool) $this->option('list')) {
            return $this->listPauses();
        }

        $input = trim((string) $this->option('competitor'));
        if ($input === '') {
            $this->error('--competitor is required (or use --list).');

            return SymfonyCommand::FAILURE;
        }

        $competitor = ctype_digit($input)
            ? Competitor::find((int) $input)
            : Competitor::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($input)])->first();

        if ($competitor === null) {
            $this->error("No competitor matching \"{$input}\".");

            return SymfonyCommand::FAILURE;
        }

        return (bool) $this->option('resume')
            ? $this->resume($competitor)
            : $this->pause($competitor);
    }

    private function pause(Competitor $competitor): int
    {
        $until = trim((string) $this->option('until'));
        if ($until === '') {
            $this->error('--until is required. A pause with no expiry is one nobody remembers to lift.');

            return SymfonyCommand::FAILURE;
        }

        try {
            $date = Carbon::parse($until)->startOfDay();
        } catch (\Throwable) {
            $this->error('--until must be a date, e.g. --until=2026-09-05');

            return SymfonyCommand::FAILURE;
        }

        if ($date->isBefore(now()->startOfDay())) {
            $this->error('--until is in the past; that pause would already have expired.');

            return SymfonyCommand::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->reportState($competitor, $date);

        if (! $apply) {
            $this->newLine();
            $this->info('Nothing written — re-run with --apply.');

            return SymfonyCommand::SUCCESS;
        }

        $competitor->forceFill([
            'pricing_paused_until' => $date->toDateString(),
            'pricing_pause_reason' => trim((string) $this->option('reason')) ?: null,
        ])->save();

        $this->newLine();
        $this->info(sprintf(
            '%s is paused from pricing until %s (inclusive). It resumes by itself the next day.',
            (string) $competitor->name,
            $date->format('Y-m-d'),
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function resume(Competitor $competitor): int
    {
        if ($competitor->pricing_paused_until === null) {
            $this->warn(sprintf('%s is not paused.', (string) $competitor->name));

            return SymfonyCommand::SUCCESS;
        }

        if (! (bool) $this->option('apply')) {
            $this->info(sprintf(
                'Would lift the pause on %s (currently until %s). Nothing written — re-run with --apply.',
                (string) $competitor->name,
                $competitor->pricing_paused_until->format('Y-m-d'),
            ));

            return SymfonyCommand::SUCCESS;
        }

        $competitor->forceFill(['pricing_paused_until' => null, 'pricing_pause_reason' => null])->save();
        $this->info(sprintf('%s can influence pricing again from the next run.', (string) $competitor->name));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * The honest part: say whether this pause actually changes anything today,
     * because for a silent feed it does not, and pretending otherwise would let
     * someone believe a problem had been dealt with.
     */
    private function reportState(Competitor $competitor, Carbon $until): void
    {
        $maxAge = 30;
        $cutoff = now()->subDays($maxAge);

        $lastRow = CompetitorPrice::where('competitor_id', $competitor->id)->max('recorded_at');
        $total = CompetitorPrice::where('competitor_id', $competitor->id)->count();
        $fresh = CompetitorPrice::where('competitor_id', $competitor->id)
            ->where('recorded_at', '>=', $cutoff)
            ->count();

        $this->newLine();
        $this->info(sprintf('── %s ──', (string) $competitor->name));
        $this->line(sprintf('   rows: %s total, %s inside the %d-day pricing window', number_format($total), number_format($fresh), $maxAge));
        $this->line(sprintf('   newest row: %s', $lastRow === null ? 'never' : Carbon::parse($lastRow)->format('Y-m-d')));
        $this->line(sprintf('   pause would run until: %s (%d days)', $until->format('Y-m-d'), (int) now()->startOfDay()->diffInDays($until)));

        $this->newLine();
        if ($fresh === 0) {
            $this->warn('   This changes NOTHING today.');
            $this->line('   Every row is already outside the pricing window, so this competitor is');
            $this->line('   not influencing any price right now. Age has already done the work.');
            $this->newLine();
            $this->line('   What the pause DOES do: when the feed is repaired, fresh rows re-enter');
            $this->line('   the window instantly and affected prices snap back to undercut with no');
            $this->line('   review. The pause holds that off until the date, so the return is a');
            $this->line('   decision rather than an event.');

            return;
        }

        $this->warn(sprintf('   %s product-price row(s) are currently INSIDE the window.', number_format($fresh)));
        $this->line('   Pausing removes this competitor from pricing immediately, so any product');
        $this->line('   it currently undercuts or floors will reprice on the next run. Preview');
        $this->line('   that with:  php artisan pricing:undercut-competitors   (dry-run)');
    }

    private function listPauses(): int
    {
        $paused = Competitor::query()->whereNotNull('pricing_paused_until')->orderBy('name')->get();

        if ($paused->isEmpty()) {
            $this->info('No competitor is paused from pricing.');

            return SymfonyCommand::SUCCESS;
        }

        $this->table(
            ['Competitor', 'Paused until', 'Active?', 'Reason'],
            $paused->map(static fn (Competitor $c): array => [
                (string) $c->name,
                $c->pricing_paused_until?->format('Y-m-d') ?? '-',
                $c->pricingPaused() ? 'yes' : 'EXPIRED',
                substr((string) $c->pricing_pause_reason, 0, 50),
            ])->all(),
        );

        $this->line('  An EXPIRED row is harmless — the competitor already prices normally again.');

        return SymfonyCommand::SUCCESS;
    }
}
