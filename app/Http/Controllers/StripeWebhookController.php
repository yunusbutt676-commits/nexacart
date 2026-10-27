<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout)
    {
        $secret = config('services.stripe.webhook_secret');

        abort_unless($secret, 503, 'Webhook secret is missing.');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (UnexpectedValueException|SignatureVerificationException $exception) {
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        if ($event->livemode) {
            return response()->json(['message' => 'Sandbox events only.'], 400);
        }

        if (in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.expired',
            'checkout.session.async_payment_succeeded',
        ], true)) {
            $checkout->apply($event->data->object);
        }

        return response()->json(['received' => true]);
    }
}