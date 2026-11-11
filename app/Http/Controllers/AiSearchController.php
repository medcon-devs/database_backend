<?php

namespace App\Http\Controllers;

use App\Services\AiSearchIntentService;
use App\Services\SmartContactSearchService;
use App\Services\ContactSortingService;
use Illuminate\Http\Request;

class AiSearchController extends Controller
{
    public function search(
        Request $request,
        AiSearchIntentService $intentService,
        SmartContactSearchService $searchService,
        ContactSortingService $sorting
    ) {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:500',
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
            ...$sorting->rules(),
        ]);

        $intent = $intentService->interpret(trim($validated['query']));
        $payload = $searchService->search(
            $intent,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['perPage'] ?? 30),
            $validated['sort_by'] ?? null,
            $validated['sort_direction'] ?? null
        );
        $payload['interpretation'] = $intent;

        return response()->json($payload, $payload['status_code'] ?? 200);
    }
}
