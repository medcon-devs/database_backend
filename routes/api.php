<?php

use App\Http\Controllers\AiSearchController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactMediaGroupController;
use App\Http\Controllers\ContactBankDetailController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactDocumentController;
use App\Http\Controllers\ContactSubmissionController;
use App\Http\Controllers\CSVController;
// use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\MicrosoftAuthController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SpeakerController;
use App\Http\Controllers\SpeakerDocumentController;
use App\Http\Controllers\StickyNoteController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppCampaignController;
use App\Http\Controllers\WhatsAppConsentController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WhatsAppConversationController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
// Password authentication is disabled. Microsoft Entra is the only login method.
// Route::post('login', [AuthController::class, 'login']);
Route::post('refresh', [AuthController::class, 'refreshToken']);
// Forgot-password is not needed while password authentication is disabled.
// Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
//     ->middleware('throttle:5,1');
Route::get('auth/microsoft/config', [MicrosoftAuthController::class, 'configuration']);
Route::get('auth/microsoft/redirect', [MicrosoftAuthController::class, 'redirectToMicrosoft'])
    ->middleware('throttle:20,1');
Route::get('auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])
    ->middleware('throttle:30,1');
Route::post('auth/microsoft/exchange', [MicrosoftAuthController::class, 'exchange'])
    ->middleware('throttle:10,1');
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);
Route::post('v1/whatsapp/consents', [WhatsAppConsentController::class, 'store'])
    ->middleware('throttle:60,1');
Route::post('v1/contact-submissions', [ContactSubmissionController::class, 'store'])
    ->middleware('throttle:60,1');

Route::get('/test-timezone', function () {
    return now()->toDateTimeString(); // This should return the current time in Dubai
});
Route::group(['middleware' => ['auth:api', 'active.user']], function () {
    Route::get('contacts', [ContactController::class, 'index'])->middleware('permission:contacts.view');
    Route::post('contacts', [ContactController::class, 'store'])->middleware('permission:contacts.create');
    Route::get('contacts/export', [CSVController::class, 'exportCSVDataToExcel'])->middleware('permission:contacts.export');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->middleware('permission:contacts.view');
    Route::patch('contacts/{contact}', [ContactController::class, 'update'])->middleware('permission:contacts.edit');
    Route::patch('contacts/{contact}/status', [ContactController::class, 'updateStatus'])->middleware('permission:contacts.archive');
    Route::get('contacts/{contact}/bank-details', [ContactBankDetailController::class, 'show'])->middleware('permission:contact_bank_details.view');
    Route::put('contacts/{contact}/bank-details', [ContactBankDetailController::class, 'update'])->middleware('permission:contact_bank_details.edit');
    Route::get('contacts/{contact}/documents', [ContactDocumentController::class, 'index'])->middleware('permission:contact_documents.view');
    Route::post('contacts/{contact}/documents/upload-signature', [ContactDocumentController::class, 'uploadSignature'])->middleware(['permission:contact_documents.upload', 'throttle:30,1']);
    Route::post('contacts/{contact}/documents', [ContactDocumentController::class, 'store'])->middleware(['permission:contact_documents.upload', 'throttle:30,1']);
    Route::get('contacts/{contact}/documents/{file}/download', [ContactDocumentController::class, 'download'])->middleware(['permission:contact_documents.download', 'throttle:30,1']);
    Route::delete('contacts/{contact}/documents/{file}', [ContactDocumentController::class, 'destroy'])->middleware(['permission:contact_documents.delete', 'throttle:30,1']);
    Route::get('contacts/{contact}/download-activity', [ContactDocumentController::class, 'activity'])->middleware('permission:contact_download_activity.view');
    Route::get('contacts/{contact}/sticky-notes', [StickyNoteController::class, 'index'])->middleware('permission:contacts.view');
    Route::post('contacts/{contact}/sticky-notes', [StickyNoteController::class, 'store'])->middleware('permission:contacts.view');
    Route::patch('sticky-notes/{note}', [StickyNoteController::class, 'update'])->middleware('permission:contacts.view');
    Route::patch('sticky-notes/{note}/status', [StickyNoteController::class, 'updateStatus'])->middleware('permission:contacts.view');

    // Deprecated compatibility routes. They translate legacy speaker-profile IDs
    // while clients migrate to the contact-native API above.
    Route::get('speakers', [SpeakerController::class, 'index'])->middleware('permission:speakers.view,contacts.view');
    Route::post('speakers', [SpeakerController::class, 'store'])->middleware('permission:speakers.create,contacts.create');
    Route::get('speakers/{speaker}', [SpeakerController::class, 'show'])->middleware(['speaker.tenant', 'permission:speakers.view,contacts.view']);
    Route::patch('speakers/{speaker}', [SpeakerController::class, 'update'])->middleware(['speaker.tenant', 'permission:speakers.edit,contacts.edit']);
    Route::patch('speakers/{speaker}/status', [SpeakerController::class, 'updateStatus'])->middleware(['speaker.tenant', 'permission:speakers.archive,contacts.archive']);
    Route::get('speakers/{speaker}/bank-details', [SpeakerController::class, 'bankDetails'])->middleware(['speaker.tenant', 'permission:speaker_bank_details.view,contact_bank_details.view']);
    Route::put('speakers/{speaker}/bank-details', [SpeakerController::class, 'updateBankDetails'])->middleware(['speaker.tenant', 'permission:speaker_bank_details.edit,contact_bank_details.edit']);
    Route::get('speakers/{speaker}/documents', [SpeakerDocumentController::class, 'index'])->middleware(['speaker.tenant', 'permission:speaker_documents.view,contact_documents.view']);
    Route::post('speakers/{speaker}/documents/upload-signature', [SpeakerDocumentController::class, 'uploadSignature'])->middleware(['speaker.tenant', 'permission:speaker_documents.upload,contact_documents.upload', 'throttle:30,1']);
    Route::post('speakers/{speaker}/documents', [SpeakerDocumentController::class, 'store'])->middleware(['speaker.tenant', 'permission:speaker_documents.upload,contact_documents.upload', 'throttle:30,1']);
    Route::get('speakers/{speaker}/documents/{file}/download', [SpeakerDocumentController::class, 'download'])->middleware(['speaker.tenant', 'permission:speaker_documents.download,contact_documents.download', 'throttle:30,1']);
    Route::delete('speakers/{speaker}/documents/{file}', [SpeakerDocumentController::class, 'destroy'])->middleware(['speaker.tenant', 'permission:speaker_documents.delete,contact_documents.delete', 'throttle:30,1']);
    Route::get('speakers/{speaker}/download-activity', [SpeakerDocumentController::class, 'activity'])->middleware(['speaker.tenant', 'permission:speaker_download_activity.view,contact_download_activity.view']);
    Route::get('mentions/users', [MentionController::class, 'users'])
        ->middleware(['permission:mentions.create', 'throttle:60,1']);
    Route::post('mentions/messages', [MentionController::class, 'store'])
        ->middleware(['permission:mentions.create', 'throttle:20,1']);
    Route::get('mentions/messages', [MentionController::class, 'index'])
        ->middleware(['permission:mentions.read', 'throttle:60,1']);
    Route::get('contacts/{contact}/media-groups', [ContactMediaGroupController::class, 'index'])->middleware('permission:contact_media.view');
    Route::get('contacts/{contact}/media-groups/deleted', [ContactMediaGroupController::class, 'deleted'])->middleware('permission:contact_media.restore');
    Route::get('contacts/{contact}/media-groups/{group}/files/{file}/download', [ContactMediaGroupController::class, 'downloadFile'])->middleware(['permission:contact_media.view', 'throttle:60,1']);
    Route::post('contacts/{contact}/media-groups/cloudinary-signature', [ContactMediaGroupController::class, 'cloudinarySignature'])->middleware(['permission:contact_media.upload', 'throttle:30,1']);
    Route::post('contacts/{contact}/media-groups', [ContactMediaGroupController::class, 'store'])->middleware(['permission:contact_media.upload', 'throttle:30,1']);
    Route::patch('contacts/{contact}/media-groups/{group}', [ContactMediaGroupController::class, 'update'])->middleware(['permission:contact_media.edit', 'throttle:30,1']);
    Route::post('contacts/{contact}/media-groups/{group}/files', [ContactMediaGroupController::class, 'addFiles'])->middleware(['permission:contact_media.upload', 'throttle:30,1']);
    Route::post('contacts/{contact}/media-groups/{group}/restore', [ContactMediaGroupController::class, 'restore'])->middleware(['permission:contact_media.restore', 'throttle:30,1']);
    Route::post('contacts/{contact}/media-groups/{group}/files/{file}/restore', [ContactMediaGroupController::class, 'restoreFile'])->middleware(['permission:contact_media.restore', 'throttle:30,1']);
    Route::patch('contacts/{contact}/media-groups/{group}/files/{file}', [ContactMediaGroupController::class, 'replaceFile'])->middleware(['permission:contact_media.edit', 'throttle:30,1']);
    Route::delete('contacts/{contact}/media-groups/{group}/files/{file}', [ContactMediaGroupController::class, 'destroyFile'])->middleware(['permission:contact_media.delete', 'throttle:30,1']);
    Route::delete('contacts/{contact}/media-groups/{group}', [ContactMediaGroupController::class, 'destroy'])->middleware(['permission:contact_media.delete', 'throttle:30,1']);
    Route::get('contact-submissions', [ContactSubmissionController::class, 'index'])->middleware('permission:contact_submissions.view');
    Route::get('contact-submissions/{submission}', [ContactSubmissionController::class, 'show'])->middleware('permission:contact_submissions.view');
    Route::post('contact-submissions/migrate', [ContactSubmissionController::class, 'migrate'])->middleware(['permission:contact_submissions.migrate', 'throttle:10,1']);
    Route::delete('contact-submissions', [ContactSubmissionController::class, 'destroySelected'])->middleware(['permission:contact_submissions.delete', 'throttle:20,1']);
    Route::get('tags/{tag}/contacts', [TagController::class, 'contacts'])->middleware('permission:tags.view');
    Route::get('tags/{tag}/available-contacts', [TagController::class, 'availableContacts'])->middleware('permission:tags.manage_contacts');
    Route::get('tags/{tag}/available-contact-ids', [TagController::class, 'availableContactIds'])->middleware('permission:tags.manage_contacts');
    Route::post('tags/{tag}/contacts', [TagController::class, 'attachContacts'])->middleware('permission:tags.manage_contacts');
    Route::put('tags/{tag}/contacts', [TagController::class, 'syncContacts'])->middleware('permission:tags.manage_contacts');
    Route::delete('tags/{tag}/contacts', [TagController::class, 'detachContacts'])->middleware('permission:tags.manage_contacts');
    Route::get('tags', [TagController::class, 'index'])->middleware('permission:tags.view');
    Route::get('tags/{tag}', [TagController::class, 'show'])->middleware('permission:tags.view');
    Route::post('tags', [TagController::class, 'store'])->middleware('permission:tags.manage');
    Route::match(['put', 'patch'], 'tags/{tag}', [TagController::class, 'update'])->middleware('permission:tags.manage');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->middleware('permission:tags.manage');

    Route::post('test-email', [UserController::class, 'sendTestEmail'])
        ->middleware('throttle:2,1');
    Route::post('whatsapp/test-message', [WhatsAppController::class, 'sendTestTemplate'])
        ->middleware(['permission:whatsapp.test_message.send', 'throttle:5,1']);
    Route::get('whatsapp/templates', [WhatsAppController::class, 'templates'])
        ->middleware(['permission:whatsapp.templates.view', 'throttle:30,1']);
    Route::post('whatsapp/templates', [WhatsAppController::class, 'createTemplate'])
        ->middleware(['permission:whatsapp.templates.create', 'throttle:10,1']);
    Route::get('whatsapp/responses', [WhatsAppController::class, 'responses'])
        ->middleware(['permission:whatsapp.responses.view', 'throttle:30,1']);
    Route::get('whatsapp/responses/export', [WhatsAppController::class, 'exportResponses'])
        ->middleware(['permission:whatsapp.responses.export', 'throttle:10,1']);
    Route::get('whatsapp/campaigns', [WhatsAppCampaignController::class, 'index'])->middleware('permission:whatsapp.campaigns.view');
    Route::post('whatsapp/campaigns', [WhatsAppCampaignController::class, 'store'])->middleware(['permission:whatsapp.campaigns.create', 'throttle:10,1']);
    Route::post('whatsapp/campaigns/audience-preview', [WhatsAppCampaignController::class, 'previewAudience'])->middleware(['permission:whatsapp.campaigns.create', 'throttle:30,1']);
    Route::post('whatsapp/campaigns/cloudinary-signature', [WhatsAppCampaignController::class, 'cloudinarySignature'])->middleware(['permission:whatsapp.campaigns.create,whatsapp.campaigns.edit', 'throttle:30,1']);
    Route::get('whatsapp/campaigns/{campaign}', [WhatsAppCampaignController::class, 'show'])->middleware('permission:whatsapp.campaigns.view');
    Route::patch('whatsapp/campaigns/{campaign}', [WhatsAppCampaignController::class, 'update'])->middleware(['permission:whatsapp.campaigns.edit', 'throttle:10,1']);
    Route::patch('whatsapp/campaigns/{campaign}/content', [WhatsAppCampaignController::class, 'updateContent'])->middleware('permission:whatsapp.campaigns.edit');
    Route::get('whatsapp/campaigns/{campaign}/recipients', [WhatsAppCampaignController::class, 'recipients'])->middleware('permission:whatsapp.campaigns.view');
    Route::get('whatsapp/campaigns/{campaign}/recipients/export', [WhatsAppCampaignController::class, 'exportRecipients'])->middleware(['permission:whatsapp.campaigns.export', 'throttle:10,1']);
    Route::get('whatsapp/campaigns/{campaign}/responses', [WhatsAppCampaignController::class, 'responses'])->middleware('permission:whatsapp.campaigns.view');
    Route::get('whatsapp/campaigns/{campaign}/responses/export', [WhatsAppCampaignController::class, 'exportResponses'])->middleware(['permission:whatsapp.campaigns.export', 'throttle:10,1']);
    Route::post('whatsapp/campaigns/{campaign}/send', [WhatsAppCampaignController::class, 'send'])->middleware(['permission:whatsapp.campaigns.send', 'throttle:5,1']);
    Route::post('whatsapp/campaigns/{campaign}/cancel', [WhatsAppCampaignController::class, 'cancel'])->middleware('permission:whatsapp.campaigns.cancel');
    Route::get('whatsapp/conversations', [WhatsAppConversationController::class, 'index'])->middleware('permission:whatsapp.inbox.view');
    Route::get('whatsapp/conversations/{phone}', [WhatsAppConversationController::class, 'show'])->middleware('permission:whatsapp.inbox.view');
    Route::post('whatsapp/conversations/{phone}/messages', [WhatsAppConversationController::class, 'send'])->middleware(['permission:whatsapp.inbox.reply', 'throttle:30,1']);
    Route::post('whatsapp/conversations/{phone}/read', [WhatsAppConversationController::class, 'markRead'])->middleware('permission:whatsapp.inbox.mark_read');
    Route::post('ai-search', [AiSearchController::class, 'search'])
        ->middleware(['permission:contacts.view', 'throttle:30,1']);
    Route::post('upload-csv', [CSVController::class, 'uploadData'])->middleware('permission:contacts.import');

    Route::post('query-row', [CSVController::class, 'queryRow'])->middleware('permission:contacts.view');
    Route::post('update-row', [CSVController::class, 'updateRow'])->middleware('permission:contacts.edit');
    Route::post('delete-row', [CSVController::class, 'deleteRow'])->middleware('permission:contacts.delete,contacts.archive');
    Route::post('get_data', [CSVController::class, 'getDataByUserId'])->middleware('permission:contacts.view');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user-activity', [UserController::class, 'getUserActivity'])->middleware('permission:activity.view_own,activity.view_all');
    Route::post('user-activity-dates', [UserController::class, 'getUserActivityBetweenDates'])->middleware('permission:activity.view_own,activity.view_all');
    Route::get('countries', [UserController::class, 'getCountires']);
    Route::get('specialties', [UserController::class, 'getSpecialties']);
    Route::post('undelete', [CSVController::class, 'undeleteRecord'])->middleware('permission:contacts.restore,contacts.archive');
    Route::post('export-data', [CSVController::class, 'exportCSVDataToExcel'])->middleware('permission:contacts.export');
    Route::post('export-count', [CSVController::class, 'exportCount'])
        ->middleware(['permission:contacts.export', 'throttle:60,1']);
    Route::get('data-activity/{data_id}', [UserController::class, 'getDataActivities'])->middleware('permission:activity.view_contact');
    Route::get('emp-activity/{user_id}', [UserController::class, 'getUserActivityByUser'])->middleware('permission:activity.view_all');
    Route::get('get-user', [AuthController::class, 'getUserDetails']);
    Route::get('get-users', [UserController::class, 'getAllUsers'])->middleware('permission:users.view');
    Route::post('/users/{id}/ban', [AuthController::class, 'updateBanStatus'])->middleware('permission:users.ban');
    Route::post('/user/update-profile/{id}', [AuthController::class, 'updateProfile']);
    Route::post('/user/update-password/{id}', [AuthController::class, 'updatePassword']);
    Route::post('/user/update-name/{id}', [AuthController::class, 'updateName'])->middleware('permission:users.edit');
    // Route::post('/user/update-password/{id}', [AuthController::class, 'updatePassword']);

    Route::post('user', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::patch('user/notification-preference', [UserController::class, 'updateNotificationPreference']);
    Route::post('users/{user}/resend-password-setup', [UserController::class, 'resendPasswordSetup'])
        ->middleware(['permission:users.resend_invitation', 'throttle:5,1']);

    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:users.view,roles.manage');
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:users.view,roles.manage');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');
    Route::put('users/{user}/role', [RoleController::class, 'assign'])->middleware('permission:users.assign_roles');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
