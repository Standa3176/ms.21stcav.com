<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Actions;

use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Integrations\Clients\GoogleAnalyticsClient;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use App\Domain\ProductAutoCreate\Services\EanSearchClient;
use App\Domain\ProductAutoCreate\Services\IcecatClient;
use App\Domain\ProductAutoCreate\Services\WebImageSearchClient;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 09.1 Plan 01 — TestIntegrationAction (D-11 + D-13).
 *
 * Per-kind dispatch helper. Calls the matching client's testConnection() method
 * for the 4 wrapped integrations (supplier_api / woo_rest / bitrix_webhook /
 * anthropic_api), or for langfuse_observability does a direct HTTP GET against
 * {host}/api/public/health since the Langfuse Prism middleware reads its own
 * config at boot and does not expose a runtime test seam.
 *
 * Result writes back to last_test_* columns + Filament notification surfaces
 * success/failure inline (D-11). Authorization gate honours the policy via
 * ->authorize() (D-13).
 */
class TestIntegrationAction
{
    public static function make(): Action
    {
        return Action::make('test_connection')
            ->label('Test connection')
            ->icon('heroicon-o-bolt')
            ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (IntegrationCredential $record): void {
                $result = self::dispatch($record);

                $record->update([
                    'last_test_at' => now(),
                    'last_test_status' => $result->ok ? IntegrationTestStatus::Ok : IntegrationTestStatus::Failed,
                    'last_test_error' => $result->error,
                    'last_test_latency_ms' => $result->latencyMs,
                ]);

                if ($result->ok) {
                    Notification::make()
                        ->title("Connection OK ({$result->latencyMs}ms)")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Connection failed')
                        ->body($result->error)
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Per-kind dispatch. Public so feature tests can verify the routing without
     * a full Livewire round-trip.
     */
    public static function dispatch(IntegrationCredential $record): IntegrationTestResult
    {
        return match ($record->kind) {
            IntegrationCredentialKind::SupplierApi => app(SupplierClient::class)->testConnection(),
            IntegrationCredentialKind::WooRest => app(WooClient::class)->testConnection(),
            IntegrationCredentialKind::BitrixWebhook => app(BitrixClient::class)->testConnection(),
            IntegrationCredentialKind::AnthropicApi => app(ClaudeClient::class)->testConnection(),
            IntegrationCredentialKind::LangfuseObservability => self::testLangfuse(),
            IntegrationCredentialKind::SupplierDb => self::testSupplierDb(),
            IntegrationCredentialKind::Icecat => app(IcecatClient::class)->testConnection(),
            IntegrationCredentialKind::EanSearch => app(EanSearchClient::class)->testConnection(),
            IntegrationCredentialKind::ImageSearch => app(WebImageSearchClient::class)->testConnection(),
            IntegrationCredentialKind::GoogleAnalytics => app(GoogleAnalyticsClient::class)->testConnection(),
            default => IntegrationTestResult::failed('Unknown kind: '.($record->kind?->value ?? 'null'), 0),
        };
    }

    private static function testLangfuse(): IntegrationTestResult
    {
        $start = microtime(true);

        try {
            $creds = app(IntegrationCredentialResolver::class)
                ->for(IntegrationCredentialKind::LangfuseObservability);

            $response = Http::timeout(10)->get(rtrim($creds['host'], '/').'/api/public/health');
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return IntegrationTestResult::ok($latency);
            }

            return IntegrationTestResult::failed("HTTP {$response->status()} from /api/public/health", $latency);
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * Quick task 260504-ld8 — Supplier DB (remote MySQL) auth probe.
     *
     * One-shot mysqli connection — NOT a registered Laravel connection.
     * Phase 2 will register a runtime connection from the same creds for
     * the actual data sync; this test only proves auth + reachability.
     *
     * Surface: connect_errno (auth/network), latency_ms.
     */
    private static function testSupplierDb(): IntegrationTestResult
    {
        $start = microtime(true);

        try {
            $creds = app(IntegrationCredentialResolver::class)
                ->for(IntegrationCredentialKind::SupplierDb);

            // Suppress mysqli's default warning-on-failure so we can return
            // a clean IntegrationTestResult instead of leaking PHP warnings
            // into the Filament notification. mysqli_report() returns bool
            // in PHP 8.4 (not the previous mode), so we don't try to restore
            // it — the suppression is process-local to this request anyway.
            mysqli_report(MYSQLI_REPORT_OFF);

            $mysqli = @new \mysqli(
                $creds['host'],
                $creds['username'],
                $creds['password'],
                $creds['database'],
                (int) $creds['port'],
            );

            if ($mysqli->connect_errno !== 0) {
                $latency = (int) round((microtime(true) - $start) * 1000);

                return IntegrationTestResult::failed(
                    "MySQL connect_errno={$mysqli->connect_errno}: {$mysqli->connect_error}",
                    $latency,
                );
            }

            $result = $mysqli->query('SELECT 1');
            $mysqli->close();
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($result === false) {
                return IntegrationTestResult::failed('SELECT 1 query failed', $latency);
            }

            return IntegrationTestResult::ok($latency);
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }
}
