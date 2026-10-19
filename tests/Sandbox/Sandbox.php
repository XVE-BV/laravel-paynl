<?php

namespace Xve\LaravelPaynl\Tests\Sandbox;

use Dotenv\Dotenv;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\Payment;

/**
 * Shared setup for the tests that call the real Pay. API with test credentials.
 * Credentials come from the environment or a git-ignored .env.sandbox (see .env.sandbox.example).
 */
final class Sandbox
{
    private static ?array $env = null;

    public static function env(string $key, ?string $default = null): ?string
    {
        if (self::$env === null) {
            $file = dirname(__DIR__, 2).'/.env.sandbox';
            self::$env = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
        }

        $value = getenv($key);

        return filled($value) ? $value : (filled(self::$env[$key] ?? null) ? self::$env[$key] : $default);
    }

    /**
     * Points the package at the sandbox account, or skips the test when no credentials are set.
     */
    public static function configure(): void
    {
        foreach (['PAYNL_SANDBOX_TOKEN_CODE', 'PAYNL_SANDBOX_API_TOKEN', 'PAYNL_SANDBOX_SERVICE_ID'] as $key) {
            if (! filled(self::env($key))) {
                test()->markTestSkipped("{$key} is not set (see .env.sandbox.example).");
            }
        }

        config([
            'paynl.token_code' => self::env('PAYNL_SANDBOX_TOKEN_CODE'),
            'paynl.api_token' => self::env('PAYNL_SANDBOX_API_TOKEN'),
            'paynl.service_id' => self::env('PAYNL_SANDBOX_SERVICE_ID'),
            'paynl.testmode' => filter_var(self::env('PAYNL_SANDBOX_TESTMODE', 'true'), FILTER_VALIDATE_BOOL),
            'paynl.stats.object' => self::env('PAYNL_SANDBOX_STATS_OBJECT', 'xve/laravel-paynl'),
            'paynl.stats.tool' => 'laravel-paynl sandbox tests',
        ]);

        // A public url (e.g. an Expose/ngrok tunnel) lets Pay. reach the exchange of a locally served app
        if (filled($url = self::env('PAYNL_SANDBOX_URL'))) {
            config(['app.url' => $url]);
            url()->forceRootUrl($url);
        }
    }

    public static function terminal(): string
    {
        $terminal = self::env('PAYNL_SANDBOX_TERMINAL');
        if (! filled($terminal)) {
            test()->markTestSkipped('PAYNL_SANDBOX_TERMINAL is not set: the terminal scenarios need a real terminal (TH-####-####).');
        }

        return $terminal;
    }

    /**
     * In cents.
     */
    public static function terminalAmount(): int
    {
        return (int) self::env('PAYNL_SANDBOX_TERMINAL_AMOUNT', '1');
    }

    /**
     * Polls like a POS should: first check after 3 seconds, then at most once every 2 seconds
     * (Pay. allows one status call per second).
     */
    public static function waitForFinalStatus(string $orderId, ?int $timeout = null): Payment
    {
        $paynl = app(PaynlService::class);
        $deadline = time() + ($timeout ?? (int) self::env('PAYNL_SANDBOX_TIMEOUT', '180'));

        sleep(3);
        do {
            $payment = $paynl->getStatus($orderId);
            if ($payment->status->isFinal()) {
                return $payment;
            }
            sleep(2);
        } while (time() < $deadline);

        return $payment;
    }

    /**
     * Instructions for whoever stands at the terminal; written straight to the console.
     */
    public static function say(string $message): void
    {
        fwrite(STDERR, PHP_EOL.'  >>> '.$message.PHP_EOL);
    }
}
