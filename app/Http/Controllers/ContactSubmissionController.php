<?php

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use App\Services\ContactSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactSubmissionController extends Controller
{
    public function store(Request $request, ContactSubmissionService $submissions)
    {
        $input = $request->validate([
            'source_domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s().\/-]{8,32}$/'],
            'country' => ['nullable', 'string', 'max:255'],
            'speciality' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:50'],
        ]);

        $submission = $submissions->submit($input);

        return response()->json([
            'data' => [
                'submission_id' => $submission->id,
                'status' => $submission->status,
                'submission_type' => $submission->submission_type,
            ],
            'status_code' => 202,
            'message' => 'Contact data received and queued for administrator review.',
            'status' => true,
        ], 202);
    }

    public function index(Request $request, ContactSubmissionService $submissions)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        $input = $request->validate([...$this->filterRules(),
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $input['status'] ??= 'pending';
        $query = $submissions->query($input);
        $summaryQuery = clone $query;
        $summary = [
            'total' => (clone $summaryQuery)->count(),
            'new' => (clone $summaryQuery)->where('submission_type', 'new')->count(),
            'update' => (clone $summaryQuery)->where('submission_type', 'update')->count(),
        ];
        $rows = $query->latest('id')->paginate((int) ($input['per_page'] ?? 50));

        return response()->json([
            'data' => $rows->items(),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
                'total' => $rows->total(), 'last_page' => $rows->lastPage(),
            ],
            'status_code' => 200,
            'message' => 'Pending contact submissions retrieved successfully.',
            'status' => true,
        ]);
    }

    public function show(Request $request, ContactSubmission $submission, ContactSubmissionService $submissions)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        return response()->json([
            'data' => $submissions->details($submission),
            'status_code' => 200,
            'message' => 'Contact submission comparison retrieved successfully.',
            'status' => true,
        ]);
    }

    public function migrate(Request $request, ContactSubmissionService $submissions)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        $input = $request->validate([...$this->selectionRules(), ...$this->filterRules()]);
        $ids = $this->selectedIds($input, $submissions);
        $result = $submissions->migrate($ids, $request->user());

        return response()->json([
            'data' => $result,
            'status_code' => 200,
            'message' => 'Selected contact submissions migrated successfully.',
            'status' => true,
        ]);
    }

    public function destroySelected(Request $request, ContactSubmissionService $submissions)
    {
        if ($response = $this->adminOnly($request)) {
            return $response;
        }

        $input = $request->validate([...$this->selectionRules(), ...$this->filterRules()]);
        $ids = $this->selectedIds($input, $submissions);
        $rejected = $submissions->reject($ids, $request->user());

        return response()->json([
            'data' => ['rejected' => $rejected],
            'status_code' => 200,
            'message' => 'Selected contact submissions rejected successfully.',
            'status' => true,
        ]);
    }

    private function selectedIds(array $input, ContactSubmissionService $submissions): array
    {
        if (($input['all'] ?? false) === true) {
            return $submissions->query([...$input, 'status' => 'pending'])->pluck('id')->all();
        }

        return array_values(array_unique(array_map('intval', $input['ids'] ?? [])));
    }

    private function selectionRules(): array
    {
        return [
            'ids' => ['required_without:all', 'array', 'min:1', 'max:10000'],
            'ids.*' => ['integer', 'distinct', Rule::exists('contact_submissions', 'id')],
            'all' => ['required_without:ids', 'boolean'],
        ];
    }

    private function filterRules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['pending', 'migrated', 'rejected'])],
            'submission_type' => ['nullable', Rule::in(['new', 'update'])],
            'source_domain' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:150'],
        ];
    }

    private function adminOnly(Request $request)
    {
        return null;
    }
}
