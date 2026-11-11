<?php

namespace App\Http\Controllers;

use App\Exports\WhatsAppCampaignRecipientsExport;
use App\Exports\WhatsAppResponsesExport;
use App\Jobs\SendWhatsAppCampaignRecipient;
use App\Models\Tag;
use App\Models\WhatsAppCampaign;
use App\Services\CloudinaryUploadService;
use App\Services\WhatsAppCampaignService;
use App\Services\WhatsAppReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class WhatsAppCampaignController extends Controller
{
    public function cloudinarySignature(Request $request, CloudinaryUploadService $cloudinary)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate([
            'campaign_name' => ['required_without:campaign_id', 'nullable', 'string', 'max:150'],
            'campaign_id' => ['required_without:campaign_name', 'nullable', 'integer', Rule::exists('whatsapp_campaigns', 'id')],
        ]);
        $name = isset($input['campaign_id'])
            ? WhatsAppCampaign::findOrFail($input['campaign_id'])->name
            : $input['campaign_name'];

        try {
            $signature = $cloudinary->campaignSignature($name);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'data' => null, 'status_code' => 503,
                'message' => $exception->getMessage(), 'status' => false,
            ], 503);
        }

        return response()->json([
            'data' => $signature, 'status_code' => 200,
            'message' => 'Cloudinary campaign upload signature created successfully.',
            'status' => true,
        ]);
    }

    public function previewAudience(Request $request, WhatsAppCampaignService $campaigns)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate([
            'template_name' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['required', 'string', 'max:20'],
            'contact_ids' => ['nullable', 'array', 'max:10000', 'required_without:tag_ids'],
            'contact_ids.*' => ['integer', Rule::exists('csv_data', 'id')],
            'tag_ids' => ['nullable', 'array', 'max:100', 'required_without:contact_ids'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'require_opt_in' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $campaigns->preview($input),
            'status_code' => 200,
            'message' => 'WhatsApp campaign audience preview calculated successfully.',
            'status' => true,
        ]);
    }

    public function index(Request $request)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'sending', 'completed', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $campaigns = WhatsAppCampaign::with('creator:id,name,email')
            ->withCount([
                'recipients as accepted_count' => fn ($q) => $q->whereIn('status', ['accepted', 'sent', 'delivered', 'read']),
                'recipients as delivered_count' => fn ($q) => $q->whereIn('status', ['delivered', 'read']),
                'recipients as read_count' => fn ($q) => $q->where('status', 'read'),
                'recipients as failed_count' => fn ($q) => $q->where('status', 'failed'),
                'recipients as responded_count' => fn ($q) => $q->where('response_count', '>', 0),
            ])
            ->when(isset($input['status']), fn ($q) => $q->where('status', $input['status']))
            ->latest()->paginate((int) ($input['per_page'] ?? 25));

        return $this->paginated($campaigns, 'WhatsApp campaigns retrieved successfully.');
    }

    public function store(Request $request, WhatsAppCampaignService $campaigns)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template_name' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['required', 'string', 'max:20'],
            'template_category' => ['nullable', Rule::in(['MARKETING', 'UTILITY', 'AUTHENTICATION'])],
            'components' => ['nullable', 'array', 'max:30'],
            'components.*' => ['required', 'array'],
            'contact_ids' => ['nullable', 'array', 'max:10000', 'required_without:tag_ids'],
            'contact_ids.*' => ['integer', Rule::exists('csv_data', 'id')],
            'tag_ids' => ['nullable', 'array', 'max:100', 'required_without:contact_ids'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'require_opt_in' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($request->has('components')) {
            $input['components'] = $request->input('components');
        }
        $campaign = $campaigns->create($input, $request->user()->id);

        return response()->json([
            'data' => $campaign, 'status_code' => 201,
            'message' => 'WhatsApp campaign created successfully.', 'status' => true,
        ], 201);
    }

    public function show(Request $request, WhatsAppCampaign $campaign)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $campaign->load('creator:id,name,email')->loadCount([
            'recipients',
            'recipients as responded_count' => fn ($q) => $q->where('response_count', '>', 0),
        ]);
        $statusCounts = $campaign->recipients()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $successfullyDeliveredCount = $campaign->recipients()->whereIn('status', ['delivered', 'read'])->count();
        $respondedCount = (int) $campaign->responded_count;
        $responseRate = $successfullyDeliveredCount > 0
            ? round(($respondedCount / $successfullyDeliveredCount) * 100, 2)
            : 0.0;
        $tags = Tag::whereIn('id', $campaign->tag_ids ?? [])
            ->withCount('contacts')
            ->get(['id', 'name']);
        $campaign->setRelation('tags', $tags);
        $campaign->setAttribute('contact_ids', array_values($campaign->contact_ids ?? []));
        $campaign->setAttribute('tag_ids', array_values($campaign->tag_ids ?? []));

        return response()->json(['data' => [
            'campaign' => $campaign,
            'status_counts' => $statusCounts,
            'analytics' => [
                'eligible_recipient_count' => (int) $campaign->recipients_count,
                'successfully_delivered_count' => $successfullyDeliveredCount,
                'responded_count' => $respondedCount,
                'response_rate' => $responseRate,
            ],
            'status_count_semantics' => [
                'basis' => 'latest_recipient_status',
                'mutually_exclusive' => true,
                'read_included_in_delivered_bucket' => false,
            ],
        ], 'status_code' => 200, 'message' => 'WhatsApp campaign retrieved successfully.', 'status' => true]);
    }

    public function update(
        Request $request,
        WhatsAppCampaign $campaign,
        WhatsAppCampaignService $campaigns
    ) {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json([
                'code' => 'WHATSAPP_CAMPAIGN_ALREADY_STARTED',
                'message' => 'Campaign sending has already started and it can no longer be edited.',
                'status' => false,
            ], 409);
        }

        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'template_name' => ['sometimes', 'required', 'string', 'max:150', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['sometimes', 'required', 'string', 'max:20'],
            'components' => ['sometimes', 'array', 'max:30'],
            'components.*' => ['required', 'array'],
            'contact_ids' => ['sometimes', 'array', 'max:10000'],
            'contact_ids.*' => ['integer', Rule::exists('csv_data', 'id')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'require_opt_in' => ['sometimes', 'boolean'],
        ]);
        if ($request->has('components')) {
            $input['components'] = $request->input('components');
        }

        try {
            $updated = $campaigns->update($campaign, $input);
        } catch (\LogicException) {
            return response()->json([
                'code' => 'WHATSAPP_CAMPAIGN_ALREADY_STARTED',
                'message' => 'Campaign sending has already started and it can no longer be edited.',
                'status' => false,
            ], 409);
        }

        return response()->json([
            'data' => $updated,
            'status_code' => 200,
            'message' => 'WhatsApp campaign updated successfully.',
            'status' => true,
        ]);
    }

    public function updateContent(
        Request $request,
        WhatsAppCampaign $campaign,
        WhatsAppCampaignService $campaigns
    ) {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json([
                'message' => 'Campaign content can only be updated before sending begins.',
                'status' => false,
            ], 409);
        }
        $input = $request->validate([
            'components' => ['required', 'array', 'max:30'],
            'components.*' => ['required', 'array'],
            'components.*.type' => ['required', 'string', 'max:50'],
        ]);
        $input['components'] = $request->input('components');

        return response()->json([
            'data' => $campaigns->updateComponents($campaign, $input['components']),
            'status_code' => 200,
            'message' => 'Campaign content updated successfully.',
            'status' => true,
        ]);
    }

    public function recipients(Request $request, WhatsAppCampaign $campaign, WhatsAppReportService $reports)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate($this->recipientFilterRules());
        $rows = $reports->campaignRecipients($campaign, $input)
            ->latest('id')->paginate((int) ($input['per_page'] ?? 50));

        return $this->paginated($rows, 'Campaign recipients retrieved successfully.');
    }

    public function responses(Request $request, WhatsAppCampaign $campaign, WhatsAppReportService $reports)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        $input = $request->validate($this->responseFilterRules());
        $responses = $reports->campaignResponses($campaign, $input)
            ->latest('received_at')->latest('id')->paginate((int) ($input['per_page'] ?? 50));
        $choices = (clone $reports->campaignResponses($campaign, $input))->setEagerLoads([])
            ->selectRaw('content, COUNT(*) as total')->groupBy('content')->orderByDesc('total')->get();
        $result = $this->paginatedData($responses);
        $result['summary'] = ['total_responses' => $responses->total(), 'choices' => $choices];

        return response()->json(['data' => $result['data'], 'summary' => $result['summary'], 'pagination' => $result['pagination'], 'status_code' => 200, 'message' => 'Campaign responses retrieved successfully.', 'status' => true]);
    }

    public function exportResponses(Request $request, WhatsAppCampaign $campaign, WhatsAppReportService $reports)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        $input = $request->validate($this->responseFilterRules());

        return Excel::download(
            new WhatsAppResponsesExport($reports->campaignResponses($campaign, $input)),
            'whatsapp-campaign-'.$campaign->id.'-responses-'.now()->format('Y-m-d').'.xlsx'
        );
    }

    public function exportRecipients(Request $request, WhatsAppCampaign $campaign, WhatsAppReportService $reports)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        $input = $request->validate($this->recipientFilterRules());

        return Excel::download(
            new WhatsAppCampaignRecipientsExport($reports->campaignRecipients($campaign, $input)),
            'whatsapp-campaign-'.$campaign->id.'-recipients-'.now()->format('Y-m-d').'.xlsx'
        );
    }

    public function send(Request $request, WhatsAppCampaign $campaign)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['code' => 'WHATSAPP_CAMPAIGN_CANNOT_BE_SCHEDULED', 'message' => 'This campaign can no longer be sent or scheduled because sending has already started.', 'status' => false], 409);
        }
        $input = $request->validate([
            'scheduled_at' => ['sometimes', 'required', 'date', 'after:now'],
        ]);
        if ($campaign->recipient_count < 1) {
            return response()->json(['message' => 'The campaign has no eligible recipients.', 'status' => false], 422);
        }

        if (array_key_exists('scheduled_at', $input)) {
            $scheduled = DB::transaction(function () use ($campaign, $input) {
                $locked = WhatsAppCampaign::lockForUpdate()->findOrFail($campaign->id);
                if (! in_array($locked->status, ['draft', 'scheduled'], true)) {
                    return null;
                }
                $locked->update([
                    'status' => 'scheduled',
                    'scheduled_at' => $input['scheduled_at'],
                    'started_at' => null,
                ]);

                return $locked->fresh();
            });
            if (! $scheduled) {
                return response()->json(['code' => 'WHATSAPP_CAMPAIGN_CANNOT_BE_SCHEDULED', 'message' => 'This campaign can no longer be scheduled because sending has already started.', 'status' => false], 409);
            }

            return response()->json(['data' => $scheduled, 'status_code' => 202, 'message' => 'WhatsApp campaign scheduled successfully.', 'status' => true], 202);
        }

        $sending = DB::transaction(function () use ($campaign) {
            $locked = WhatsAppCampaign::lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($locked->status, ['draft', 'scheduled'], true)) {
                return null;
            }
            $locked->update(['status' => 'sending', 'started_at' => now(), 'scheduled_at' => null]);

            return $locked->fresh();
        });
        if (! $sending) {
            return response()->json(['code' => 'WHATSAPP_CAMPAIGN_CANNOT_BE_SCHEDULED', 'message' => 'This campaign can no longer be sent because sending has already started.', 'status' => false], 409);
        }
        $sending->recipients()->where('status', 'pending')->pluck('id')->each(fn ($id) => SendWhatsAppCampaignRecipient::dispatch($id)->onQueue('whatsapp'));

        return response()->json(['data' => $sending, 'status_code' => 202, 'message' => 'WhatsApp campaign queued for sending.', 'status' => true], 202);
    }

    public function cancel(Request $request, WhatsAppCampaign $campaign)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['message' => 'This campaign can no longer be cancelled.', 'status' => false], 409);
        }
        $campaign->update(['status' => 'cancelled']);

        return response()->json(['data' => $campaign, 'status_code' => 200, 'message' => 'WhatsApp campaign cancelled.', 'status' => true]);
    }

    private function adminOnly(Request $request)
    {
        // Route permission middleware is authoritative. Kept temporarily to
        // avoid touching every action signature during the legacy migration.
        return null;
    }

    private function responseFilterRules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_id' => ['nullable', 'integer', Rule::exists('csv_data', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    private function recipientFilterRules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['pending', 'sending', 'accepted', 'sent', 'delivered', 'read', 'failed', 'skipped'])],
            'responded' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    private function paginated($paginator, string $message)
    {
        $result = $this->paginatedData($paginator);

        return response()->json(['data' => $result['data'], 'pagination' => $result['pagination'], 'status_code' => 200, 'message' => $message, 'status' => true]);
    }

    private function paginatedData($paginator): array
    {
        return ['data' => $paginator->items(), 'pagination' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }
}
