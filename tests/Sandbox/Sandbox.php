<?php

namespace Xve\LaravelPaynl\Tests\Sandbox;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert as PHPUnit;
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

        // getenv() returns false for an unset variable, and filled(false) is true
        $value = getenv($key);

        return $value !== false && filled($value) ? $value : (filled(self::$env[$key] ?? null) ? self::$env[$key] : $default);
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
            'paynl.stats.object' => self::env('PAYNL_SANDBOX_STATS_OBJECT', PaynlService::PARTNER_ID),
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
     * Reads Pay.'s own check of a certification test case from the sandbox payment page
     * ("Testcase 5 - Passed 5/5, Mismatch 0/5, Missing 0/5") and fails on any field that did not pass.
     */
    public static function assertCertificationCase(Payment $payment, int $case): void
    {
        $html = Http::timeout(15)->get((string) $payment->paymentUrl)->throw()->body();

        if (! preg_match('/Testcase\s+(\d+)/i', $html, $title)) {
            PHPUnit::fail("No certification panel on {$payment->paymentUrl}: is integration.test on and the sandbox method used?");
        }
        PHPUnit::assertSame($case, (int) $title[1], "Pay. matched the order to test case {$title[1]}, not {$case}.");

        // The summary: a green, orange and red dot, each followed by "<label> <count>/<total>"
        preg_match_all('/bg-(green|orange|red)-400"><\/span>\s*<span[^>]*>\s*[^<\d]*(\d+)\/(\d+)/', $html, $summary, PREG_SET_ORDER);
        $counts = collect($summary)->mapWithKeys(fn ($match) => [$match[1] => [(int) $match[2], (int) $match[3]]]);
        PHPUnit::assertTrue($counts->has('green'), "No result summary for test case {$case} on {$payment->paymentUrl}.");

        // Every field row has a dot: green passed, orange mismatch, red missing ("Name Received: x Expected: y")
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        $failed = collect($rows[1])
            ->filter(fn (string $row) => preg_match('/bg-(orange|red)-400/', $row))
            ->map(fn (string $row) => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($row)))));

        [$passed, $total] = $counts['green'];
        self::say("Case {$case}: {$passed}/{$total} passed at Pay. ({$payment->paymentUrl})");

        PHPUnit::assertTrue($passed === $total && $failed->isEmpty(), "Test case {$case} passed {$passed}/{$total} at Pay.: ".$failed->join(', '));
    }

    /**
     * Adds a scenario result to the evidence Pay. asks for at the certification (references of a
     * successful, failed, cancelled and refunded payment, stats.object, terminal, receipt), in
     * build/paynl-certification.md (git-ignored). Add the screenshots of the kassa screens yourself.
     */
    public static function record(string $scenario, Payment $payment): void
    {
        $file = dirname(__DIR__, 2).'/build/paynl-certification.md';
        if (! is_file($file)) {
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, implode(PHP_EOL, [
                '# Pay. POS certification evidence',
                '',
                '- Sales location: '.config('paynl.service_id'),
                '- Terminal: '.self::env('PAYNL_SANDBOX_TERMINAL'),
                '- stats.object: '.config('paynl.stats.object'),
                '',
            ]));
        }

        $lines = [
            '',
            "## {$scenario} (".now()->toDateTimeString().')',
            '',
            "- Order: {$payment->orderId}",
            '- Reference: '.($payment->reference ?: '-'),
            "- Status: {$payment->status->value} ({$payment->statusCode})",
            '- Failure reason: '.($payment->failureReason ?? '-'),
        ];
        if ($payment->receipt !== null) {
            array_push($lines, '', '```', $payment->receipt->text(), '```');
        }

        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
    }

    /**
     * Instructions for whoever stands at the terminal; written straight to the console.
     */
    public static function say(string $message): void
    {
        fwrite(STDERR, PHP_EOL.'  >>> '.$message.PHP_EOL);
    }
}
