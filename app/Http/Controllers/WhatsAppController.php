<?php

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Exports\WhatsAppResponsesExport;
use App\Services\WhatsAppCloudApiService;
use App\Services\WhatsAppReportService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class WhatsAppController extends Controller
{
    public function templates(Request $request, WhatsAppTemplateService $templates)
    {
        if ($response = $this->denyNonAdministrator($request, 'view WhatsApp templates')) {
            return $response;
        }

        $validated = $request->validate([
            'after' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        try {
            $result = $templates->all(
                $validated['after'] ?? null,
                (int) ($validated['limit'] ?? 50)
            );
        } catch (WhatsAppApiException $exception) {
            return $this->templateError($exception);
        }

        return response()->json([
            'data' => $result['data'] ?? [],
            'paging' => $result['paging'] ?? null,
            'status_code' => 200,
            'message' => 'WhatsApp templates retrieved successfully.',
            'status' => true,
        ]);
    }

    public function createTemplate(Request $request, WhatsAppTemplateService $templates)
    {
        if ($response = $this->denyNonAdministrator($request, 'create WhatsApp templates')) {
            return $response;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/'],
            'category' => ['required', Rule::in(['AUTHENTICATION', 'MARKETING', 'UTILITY'])],
            'allow_category_change' => ['nullable', 'boolean'],
            'parameter_format' => ['nullable', Rule::in(['POSITIONAL', 'NAMED'])],
            'message_send_ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:2592000'],
            'components' => ['nullable', 'array', 'min:1', 'max:30'],
            'components.*' => ['required', 'array'],
            'components.*.type' => ['required', 'string', 'max:50'],
            'header_type' => ['nullable', Rule::in(['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header' => [
                'nullable',
                'string',
                'max:60',
                'prohibited_if:header_type,IMAGE,VIDEO,DOCUMENT',
            ],
            'header_example' => ['nullable', 'string', 'max:60'],
            'header_image' => ['nullable', 'required_if:header_type,IMAGE', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'header_media' => [
                'nullable',
                'required_if:header_type,VIDEO,DOCUMENT',
                'file',
                'mimes:mp4,pdf,doc,docx,xls,xlsx,ppt,pptx',
                'max:102400',
            ],
            'body' => ['required_without:components', 'nullable', 'string', 'max:1024'],
            'body_examples' => ['nullable', 'array', 'max:20'],
            'body_examples.*' => ['required', 'string', 'max:250'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['nullable', Rule::in([
                'QUICK_REPLY',
                'URL',
                'PHONE_NUMBER',
                'COPY_CODE',
                'CATALOG',
                'FLOW',
            ])],
            'buttons.*.text' => ['required', 'string', 'max:25', 'distinct'],
            'buttons.*.url' => [
                'nullable',
                'required_if:buttons.*.type,URL',
                'prohibited_unless:buttons.*.type,URL',
                'url:http,https',
                'max:2000',
            ],
            'buttons.*.phone_number' => [
                'nullable',
                'required_if:buttons.*.type,PHONE_NUMBER',
                'string',
                'max:20',
                'regex:/^\\+[1-9][0-9]{7,14}$/',
            ],
            'buttons.*.example' => ['nullable', 'string', 'max:2000'],
            'buttons.*.flow_id' => [
                'nullable',
                'required_if:buttons.*.type,FLOW',
                'string',
                'max:100',
            ],
            'buttons.*.flow_action' => [
                'nullable',
                'required_if:buttons.*.type,FLOW',
                Rule::in(['NAVIGATE', 'DATA_EXCHANGE']),
            ],
            'buttons.*.navigate_screen' => ['nullable', 'string', 'max:200'],
        ]);

        if ($request->has('components')) {
            // Preserve Meta-native component fields that are intentionally
            // future-compatible and therefore not enumerated by Laravel rules.
            $validated['components'] = $request->input('components');
        }

        $validated['header_type'] = $validated['header_type']
            ?? ($request->hasFile('header_image') ? 'IMAGE' : (filled($validated['header'] ?? null) ? 'TEXT' : 'NONE'));

        if (($validated['components'] ?? []) === []) {
            $this->validateTemplateParameters($validated);
        }

        try {
            $template = $templates->create(
                $validated,
                $request->file('header_media') ?? $request->file('header_image')
            );
        } catch (WhatsAppApiException $exception) {
            return $this->templateError($exception);
        }

        return response()->json([
            'data' => [
                'id' => $template['id'] ?? null,
                'status' => $template['status'] ?? 'PENDING',
                'category' => $template['category'] ?? $validated['category'],
                'name' => $validated['name'],
                'language' => $validated['language'],
            ],
            'status_code' => 201,
            'message' => 'WhatsApp template submitted to Meta for review.',
            'status' => true,
        ], 201);
    }

    public function responses(Request $request, WhatsAppReportService $reports)
    {
        if ($response = $this->denyNonAdministrator($request, 'view WhatsApp responses')) {
            return $response;
        }

        $validated = $request->validate($this->responseFilterRules());

        $responses = $reports->generalResponses($validated)
            ->latest('received_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 50));
        $responses->getCollection()->each(function ($message) use ($reports) {
            $attribution = $reports->attribution($message);
            $message->setAttribute('template_name', $attribution['template_name']);
            $message->setAttribute('template_language', $attribution['template_language']);
            $message->setRelation('campaign', $attribution['campaign']);
            $message->unsetRelation('contextMessage');
        });

        return response()->json([
            'data' => $responses->items(),
            'pagination' => [
                'current_page' => $responses->currentPage(),
                'per_page' => $responses->perPage(),
                'total' => $responses->total(),
                'last_page' => $responses->lastPage(),
            ],
            'status_code' => 200,
            'message' => 'WhatsApp responses retrieved successfully.',
            'status' => true,
        ]);
    }

    public function exportResponses(Request $request, WhatsAppReportService $reports)
    {
        if ($response = $this->denyNonAdministrator($request, 'export WhatsApp responses')) {
            return $response;
        }

        $validated = $request->validate($this->responseFilterRules());
        $filename = 'whatsapp-responses-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new WhatsAppResponsesExport($reports->generalResponses($validated)),
            $filename
        );
    }

    public function sendTestTemplate(Request $request, WhatsAppCloudApiService $whatsApp)
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().\/-]{8,32}$/'],
            'template_name' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/'],
            'contact_id' => ['nullable', 'integer', Rule::exists('csv_data', 'id')],
            'header_parameters' => ['nullable', 'array', 'max:1'],
            'header_parameters.*' => ['required', 'string', 'max:250'],
            'body_parameters' => ['nullable', 'array', 'max:20'],
            'body_parameters.*' => ['required', 'string', 'max:250'],
            'header_image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'components' => ['nullable', 'array', 'max:30'],
            'components.*' => ['required', 'array'],
            'components.*.type' => ['required', 'string', 'max:50'],
        ]);

        if ($request->has('components')) {
            $validated['components'] = $request->input('components');
        }

        if (filled($validated['header_image_url'] ?? null) && ($validated['header_parameters'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'header_parameters' => ['Do not send text header parameters with an image header.'],
            ]);
        }

        $components = ($validated['components'] ?? []) !== []
            ? array_values($validated['components'])
            : $this->messageTemplateComponents($validated);

        try {
            $message = $whatsApp->sendTemplate(
                $validated['to'],
                $validated['template_name'] ?? 'hello_world',
                $validated['language'] ?? 'en_US',
                $components,
                isset($validated['contact_id']) ? (int) $validated['contact_id'] : null
            );
        } catch (WhatsAppApiException $exception) {
            return response()->json([
                'code' => 'WHATSAPP_SEND_FAILED',
                'data' => [
                    'provider_code' => $exception->providerCode(),
                ],
                'status_code' => $exception->statusCode(),
                'message' => $exception->getMessage(),
                'status' => false,
            ], $exception->statusCode());
        }

        return response()->json([
            'data' => [
                'message_id' => $message->id,
                'whatsapp_message_id' => $message->whatsapp_message_id,
                'status' => $message->status,
            ],
            'status_code' => 200,
            'message' => 'WhatsApp test message accepted by Meta.',
            'status' => true,
        ]);
    }

    private function messageTemplateComponents(array $input): array
    {
        $components = [];

        if (filled($input['header_image_url'] ?? null)) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'image',
                    'image' => ['link' => $input['header_image_url']],
                ]],
            ];
        } elseif (($input['header_parameters'] ?? []) !== []) {
            $components[] = [
                'type' => 'header',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    array_values($input['header_parameters'])
                ),
            ];
        }

        if (($input['body_parameters'] ?? []) !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    array_values($input['body_parameters'])
                ),
            ];
        }

        return $components;
    }

    private function denyNonAdministrator(Request $request, string $action)
    {
        return null;
    }

    private function responseFilterRules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_id' => ['nullable', 'integer', Rule::exists('csv_data', 'id')],
            'template_name' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    private function templateError(WhatsAppApiException $exception)
    {
        return response()->json([
            'code' => 'WHATSAPP_TEMPLATE_FAILED',
            'data' => [
                'provider_code' => $exception->providerCode(),
            ],
            'status_code' => $exception->statusCode(),
            'message' => $exception->getMessage(),
            'status' => false,
        ], $exception->statusCode());
    }

    private function validateTemplateParameters(array $input): void
    {
        $errors = [];
        $headerType = $input['header_type'] ?? 'NONE';
        $headerText = (string) ($input['header'] ?? '');
        $bodyText = (string) $input['body'];

        if ($headerType === 'TEXT' && ! filled($input['header'] ?? null)) {
            $errors['header'] = ['A text header is required when header_type is TEXT.'];
        }

        if ($headerType === 'NONE' && filled($input['header_example'] ?? null)) {
            $errors['header_example'] = ['A header example requires a text header.'];
        }

        $headerVariables = $this->parameterNumbers($headerText);
        if ($this->hasInvalidParameterSyntax($headerText)) {
            $errors['header'] = ['Header parameters must use positional syntax such as {{1}}.'];
        } elseif ($headerVariables !== [] && $headerVariables !== [1]) {
            $errors['header'] = ['A text header can only contain the {{1}} parameter.'];
        } elseif ($headerVariables === [1] && ! filled($input['header_example'] ?? null)) {
            $errors['header_example'] = ['Provide an example value for the {{1}} header parameter.'];
        } elseif ($headerVariables === [] && filled($input['header_example'] ?? null)) {
            $errors['header_example'] = ['Remove the header example because the header has no parameter.'];
        }

        $bodyVariables = $this->parameterNumbers($bodyText);
        $expectedNumbers = $bodyVariables === [] ? [] : range(1, max($bodyVariables));
        if ($this->hasInvalidParameterSyntax($bodyText)) {
            $errors['body'] = ['Body parameters must use positional syntax such as {{1}} and {{2}}.'];
        } elseif ($bodyVariables !== $expectedNumbers) {
            $errors['body'] = ['Body parameters must start at {{1}} and continue without gaps.'];
        }

        $examples = array_values($input['body_examples'] ?? []);
        if (count($examples) !== count($expectedNumbers)) {
            $errors['body_examples'] = [
                'Provide exactly one example value for every body parameter, in numerical order.',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function parameterNumbers(string $text): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($numbers);

        return $numbers;
    }

    private function hasInvalidParameterSyntax(string $text): bool
    {
        preg_match_all('/\{\{([^{}]+)\}\}/', $text, $matches);

        foreach ($matches[1] ?? [] as $parameter) {
            if (! preg_match('/^\d+$/', $parameter)) {
                return true;
            }
        }

        $withoutValidParameters = preg_replace('/\{\{\d+\}\}/', '', $text);

        return str_contains($withoutValidParameters, '{{')
            || str_contains($withoutValidParameters, '}}');
    }
}
