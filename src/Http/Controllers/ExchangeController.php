<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Services\PaynlService;

class ExchangeController extends Controller
{
    public function __invoke(Request $request, PaynlService $paynl): Response
    {
        $payload = $request->all();

        try {
            $payment = $paynl->handleExchange($payload);
            PaymentStatusUpdated::dispatch($payment);
        } catch (Throwable $e) {
            // Answering FALSE makes Pay. retry the exchange later
            Log::error('Pay.nl exchange failed', ['error' => $e->getMessage(), 'payload' => $payload]);

            return response($paynl->exchangeResponse($payload, false, 'Exchange processing failed'));
        }

        return response($paynl->exchangeResponse($payload, true, 'Processed '.$payment->status->value));
    }
}
