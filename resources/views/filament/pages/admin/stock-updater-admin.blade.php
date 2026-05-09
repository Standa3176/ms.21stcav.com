<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
            Bulk maintenance actions
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Three one-click maintenance tools ported from the legacy WP Stock Updater plugin. Each
            confirms before executing. Use the buttons in the page header.
        </p>

        <dl class="mt-6 space-y-4 text-sm">
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">Reset all margin overrides</dt>
                <dd class="text-gray-600 dark:text-gray-400">
                    Sets <code>margin_basis_points = 0</code> on every <code>ProductOverride</code> row so the
                    default-tier rules become the active source for every product. Brand / category rules are
                    untouched. Use after a re-baseline when per-product overrides have drifted out of sync with
                    the operator's pricing intent.
                </dd>
            </div>

            <div>
                <dt class="font-medium text-gray-950 dark:text-white">Publish pending products</dt>
                <dd class="text-gray-600 dark:text-gray-400">
                    Flips every <code>Product.status = pending</code> with a populated <code>buy_price &gt; 0</code>
                    back to <code>publish</code>. Products with NULL or zero buy_price stay pending — they need a
                    real cost from the supplier feed before they can return to sale.
                </dd>
            </div>

            <div>
                <dt class="font-medium text-gray-950 dark:text-white">Run retention prunes</dt>
                <dd class="text-gray-600 dark:text-gray-400">
                    Triggers the full prune cascade on demand: activitylog (365d), integration_events (90d),
                    sync_errors (90d), sync_diffs (post-cutover only), competitor CSVs (90d). Same commands the
                    03:00 nightly schedule fires — running here is safe but normally redundant.
                </dd>
            </div>
        </dl>
    </div>
</x-filament-panels::page>
