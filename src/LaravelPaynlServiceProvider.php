<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Listeners\DispatchTerminalPaymentPaid;
use Xve\LaravelPaynl\Services\PaynlService;

class LaravelPaynlServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('paynl')
            ->hasConfigFile('paynl')
            ->hasRoute('paynl')
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->bind(PaynlService::class, fn ($app) => new PaynlService($app['config']->get('paynl', [])));
        $this->app->bind(PaymentGateway::class, PaynlService::class);
    }

    public function packageBooted(): void
    {
        Event::listen(PaymentStatusUpdated::class, DispatchTerminalPaymentPaid::class);
    }
}
