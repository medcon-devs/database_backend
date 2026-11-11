<?php

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppCloudApiService;
use App\Services\WhatsAppPhoneService;
use Illuminate\Http\Request;

class WhatsAppConversationController extends Controller
{
    public function index(Request $request)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $groups = WhatsAppMessage::query()
            ->selectRaw('phone, MAX(id) as latest_message_id, MAX(created_at) as latest_at')
            ->when(filled($input['search'] ?? null), fn ($q) => $q->where('phone', 'like', '%'.preg_replace('/\D+/', '', $input['search']).'%'))
            ->groupBy('phone')->orderByDesc('latest_at')->paginate((int) ($input['per_page'] ?? 30));
        $latest = WhatsAppMessage::with('contact:id,firstname,lastname,email,phone')
            ->whereIn('id', collect($groups->items())->pluck('latest_message_id'))->get()->keyBy('id');
        $data = collect($groups->items())->map(function ($group) use ($latest) {
            $message = $latest->get($group->latest_message_id);
            $lastInbound = WhatsAppMessage::where('phone', $group->phone)->where('direction', 'inbound')->latest('received_at')->first();
            $windowExpires = $lastInbound?->received_at?->copy()->addHours(24);

            return [
                'phone' => $group->phone,
                'contact' => $message?->contact,
                'latest_message' => $message,
                'unread_count' => WhatsAppMessage::where('phone', $group->phone)->where('direction', 'inbound')->whereNull('viewed_at')->count(),
                'customer_service_window_expires_at' => $windowExpires,
                'can_send_free_form' => $windowExpires?->isFuture() ?? false,
            ];
        })->values();

        return response()->json(['data' => $data, 'pagination' => ['current_page' => $groups->currentPage(), 'per_page' => $groups->perPage(), 'total' => $groups->total(), 'last_page' => $groups->lastPage()], 'status_code' => 200, 'message' => 'WhatsApp conversations retrieved successfully.', 'status' => true]);
    }

    public function show(Request $request, string $phone, WhatsAppPhoneService $phones)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $phone = $phones->normalize($phone);
        $input = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        $messages = WhatsAppMessage::with(['contact:id,firstname,lastname,email,phone', 'campaign:id,name,template_name'])
            ->where('phone', $phone)->latest('id')->paginate((int) ($input['per_page'] ?? 50));
        $lastInbound = WhatsAppMessage::where('phone', $phone)->where('direction', 'inbound')->latest('received_at')->first();
        $expires = $lastInbound?->received_at?->copy()->addHours(24);

        return response()->json(['data' => array_reverse($messages->items()), 'conversation' => ['phone' => $phone, 'customer_service_window_expires_at' => $expires, 'can_send_free_form' => $expires?->isFuture() ?? false], 'pagination' => ['current_page' => $messages->currentPage(), 'per_page' => $messages->perPage(), 'total' => $messages->total(), 'last_page' => $messages->lastPage()], 'status_code' => 200, 'message' => 'WhatsApp conversation retrieved successfully.', 'status' => true]);
    }

    public function send(Request $request, string $phone, WhatsAppPhoneService $phones, WhatsAppCloudApiService $whatsApp)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $phone = $phones->normalize($phone);
        $input = $request->validate(['message' => ['required', 'string', 'max:4096']]);
        $lastInbound = WhatsAppMessage::where('phone', $phone)->where('direction', 'inbound')->latest('received_at')->first();
        if (! $lastInbound?->received_at || $lastInbound->received_at->lt(now()->subHours(24))) {
            return response()->json(['code' => 'WHATSAPP_WINDOW_CLOSED', 'data' => ['last_inbound_at' => $lastInbound?->received_at, 'requires_template' => true], 'status_code' => 409, 'message' => 'The 24-hour customer service window is closed. Send an approved template to reopen the conversation.', 'status' => false], 409);
        }
        try {
            $message = $whatsApp->sendText($phone, $input['message'], $lastInbound->csv_data_id);
        } catch (WhatsAppApiException $exception) {
            return response()->json(['code' => 'WHATSAPP_SEND_FAILED', 'data' => ['provider_code' => $exception->providerCode()], 'status_code' => $exception->statusCode(), 'message' => $exception->getMessage(), 'status' => false], $exception->statusCode());
        }

        return response()->json(['data' => $message, 'status_code' => 201, 'message' => 'WhatsApp message accepted by Meta.', 'status' => true], 201);
    }

    public function markRead(Request $request, string $phone, WhatsAppPhoneService $phones)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        WhatsAppMessage::where('phone', $phones->normalize($phone))->where('direction', 'inbound')->whereNull('viewed_at')->update(['viewed_at' => now()]);

        return response()->json(['data' => null, 'status_code' => 200, 'message' => 'Conversation marked as viewed.', 'status' => true]);
    }

    private function adminOnly(Request $request)
    {
        return null;
    }
}
