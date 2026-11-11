<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = (string) $request->query('hub.verify_token', $request->query('hub_verify_token', ''));
        $challenge = (string) $request->query('hub.challenge', $request->query('hub_challenge', ''));
        $expectedToken = (string) config('services.whatsapp.webhook_verify_token');

        if (
            $mode !== 'subscribe'
            || $expectedToken === ''
            || ! hash_equals($expectedToken, $token)
        ) {
            return response()->json([
                'code' => 'WHATSAPP_WEBHOOK_VERIFICATION_FAILED',
                'message' => 'Webhook verification failed.',
                'status' => false,
            ], 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request, WhatsAppWebhookService $webhook)
    {
        $appSecret = (string) config('services.whatsapp.app_secret');
        if ($appSecret === '') {
            return response()->json([
                'code' => 'WHATSAPP_WEBHOOK_NOT_CONFIGURED',
                'message' => 'WhatsApp webhook signature verification is not configured.',
                'status' => false,
            ], 503);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);
        if ($signature === '' || ! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'code' => 'INVALID_WHATSAPP_SIGNATURE',
                'message' => 'Invalid WhatsApp webhook signature.',
                'status' => false,
            ], 401);
        }

        $payload = $request->json()->all();
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json([
                'code' => 'INVALID_WHATSAPP_PAYLOAD',
                'message' => 'Invalid WhatsApp webhook payload.',
                'status' => false,
            ], 400);
        }

        $webhook->process($payload);

        return response()->json(['status' => true]);
    }
}
