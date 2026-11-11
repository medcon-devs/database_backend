<?php

namespace App\Http\Controllers;

use App\Models\ContactMediaFile;
use App\Models\SpeakerProfile;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;

/**
 * @deprecated Use ContactDocumentController. This adapter remains for one
 * frontend migration period because legacy routes use speaker-profile IDs.
 */
class SpeakerDocumentController extends Controller
{
    public function index(Request $request, SpeakerProfile $speaker, ContactDocumentController $contacts)
    {
        return $contacts->index($request, $speaker->contact_id);
    }

    public function uploadSignature(
        Request $request,
        SpeakerProfile $speaker,
        CloudinaryUploadService $cloudinary,
        ContactDocumentController $contacts
    ) {
        return $contacts->uploadSignature($request, $speaker->contact_id, $cloudinary);
    }

    public function store(Request $request, SpeakerProfile $speaker, ContactDocumentController $contacts)
    {
        return $contacts->store($request, $speaker->contact_id);
    }

    public function download(
        Request $request,
        SpeakerProfile $speaker,
        ContactMediaFile $file,
        ContactDocumentController $contacts
    ) {
        return $contacts->download($request, $speaker->contact_id, $file);
    }

    public function destroy(
        Request $request,
        SpeakerProfile $speaker,
        ContactMediaFile $file,
        ContactDocumentController $contacts
    ) {
        return $contacts->destroy($request, $speaker->contact_id, $file);
    }

    public function activity(Request $request, SpeakerProfile $speaker, ContactDocumentController $contacts)
    {
        return $contacts->activity($request, $speaker->contact_id);
    }
}
