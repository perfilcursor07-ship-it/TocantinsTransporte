<?php

namespace App\Http\Controllers;

use App\Models\TourismTrip;
use App\Services\TourismPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Link público de pagamento do Turismo (enviado ao responsável pela viagem)
 * e webhook PagBank próprio — separado do pagamento do portal.
 */
class TourismPaymentController extends Controller
{
    public function show(string $token, TourismPaymentService $payments)
    {
        $trip = TourismTrip::with('bus')->where('payment_token', $token)->firstOrFail();
        $error = null;

        if (! $trip->isPaid() && $trip->status !== 'cancelled') {
            $payments->refreshStatus($trip);
            try {
                $payments->ensurePix($trip);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('tourism.payment', ['trip' => $trip, 'quote' => $trip->quote(['price_24h' => (float) $trip->price_24h]),
            'error' => $error]);
    }

    /** Consultado pela página a cada poucos segundos até o pagamento cair. */
    public function status(string $token, TourismPaymentService $payments)
    {
        $trip = TourismTrip::where('payment_token', $token)->firstOrFail();
        $payments->refreshStatus($trip);

        return response()->json([
            'paid' => $trip->isPaid(),
            'paid_at' => $trip->paid_at?->format('d/m/Y H:i'),
        ]);
    }

    public function webhook(Request $request, TourismPaymentService $payments)
    {
        Log::info('Turismo: webhook PagBank recebido', ['reference_id' => $request->input('reference_id'), 'order_id' => $request->input('id')]);

        try {
            $confirmed = $payments->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('Turismo: erro no webhook PagBank', ['error' => $e->getMessage()]);

            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true, 'confirmed' => $confirmed]);
    }
}
