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
        try {
            $payment = $paynl->handleExchange($request);
            PaymentStatusUpdated::dispatch($payment);
        } catch (Throwable $e) {
            // Answering FALSE makes Pay. retry the exchange later. No payload in the log: it holds customer data.
            Log::error('Pay.nl exchange failed', [
                'orderId' => $request->input('object.orderId') ?? $request->input('order_id'),
                'signed' => $request->header('signature-method') === 'HMAC',
                'exception' => $e,
            ]);

            return $this->respond($request, false, 'Exchange processing failed');
        }

        return $this->respond($request, true, 'Processed '.$payment->status->value);
    }

    /**
     * JSON for (signed) JSON exchanges, "TRUE| ..." for the classic ones; anything else makes Pay. retry.
     */
    private function respond(Request $request, bool $result, string $message): Response
    {
        if ($request->header('signature-method') === 'HMAC' || $request->isJson()) {
            return response(json_encode(['result' => $result, 'description' => $message]), 200, ['Content-Type' => 'application/json']);
        }

        return response(($result ? 'TRUE' : 'FALSE').'| '.$message, 200, ['Content-Type' => 'text/plain']);
    }
}
