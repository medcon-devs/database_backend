<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppCampaignRecipient;
use App\Models\CSVData;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WhatsAppIntegrationTest extends TestCase
{
    private const APP_SECRET = 'integration-test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'scout.driver' => 'null',
            'services.whatsapp.enabled' => true,
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '123456789',
            'services.whatsapp.business_account_id' => '987654321',
            'services.whatsapp.access_token' => 'test-access-token',
            'services.whatsapp.app_id' => '111222333',
            'services.whatsapp.app_secret' => self::APP_SECRET,
            'services.whatsapp.webhook_verify_token' => 'verify-me',
            'services.whatsapp.timeout' => 3,
            'services.cloudinary.cloud_name' => 'medcon-cloud',
            'services.cloudinary.api_key' => 'cloudinary-key',
            'services.cloudinary.api_secret' => 'cloudinary-secret',
            'services.cloudinary.base_folder' => 'database',
        ]);
        DB::purge();
        $this->createSchema();
    }

    public function test_meta_can_verify_the_webhook(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden()
            ->assertJsonPath('code', 'WHATSAPP_WEBHOOK_VERIFICATION_FAILED');
    }

    public function test_webhook_rejects_an_invalid_meta_signature(): void
    {
        $this->signedWebhook($this->inboundPayload('wamid.invalid', 'JOIN'), 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_WHATSAPP_SIGNATURE');

        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_join_and_stop_messages_create_auditable_consent_events(): void
    {
        $user = User::factory()->create();
        $contact = CSVData::create([
            'firstname' => 'WhatsApp',
            'lastname' => 'Doctor',
            'email' => 'whatsapp-doctor@example.test',
            'phone' => '+971 56 616 7139',
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);

        $joinPayload = $this->inboundPayload('wamid.join', 'JOIN', '1710000000');
        $this->signedWebhook($joinPayload)->assertOk();
        $this->signedWebhook($joinPayload)->assertOk();

        $this->assertDatabaseHas('whatsapp_consents', [
            'csv_data_id' => $contact->id,
            'phone' => '971566167139',
            'status' => 'opted_in',
            'source' => 'whatsapp_keyword',
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_message_id' => 'wamid.join',
            'direction' => 'inbound',
            'status' => 'received',
            'content' => 'JOIN',
        ]);
        $this->assertDatabaseCount('whatsapp_consent_events', 1);

        $this->signedWebhook($this->inboundPayload('wamid.stop', 'STOP', '1710001000'))
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_consents', [
            'phone' => '971566167139',
            'status' => 'opted_out',
        ]);
        $this->assertDatabaseCount('whatsapp_consent_events', 2);
    }

    public function test_external_api_records_auditable_opt_in_and_normalizes_phone_numbers(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://webinar.medcon.ae',
            'User-Agent' => 'Medcon Webinar Registration',
        ])->postJson('/api/v1/whatsapp/consents', [
            'phone' => '056 616 7139',
            'action' => 'opt_in',
            'source_domain' => 'webinar.medcon.ae',
            'consent_text' => 'I agree to receive Medcon updates through WhatsApp.',
            'consent_version' => 'whatsapp-marketing-v1',
            'evidence_reference' => 'teams-registration:123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.phone', '971566167139')
            ->assertJsonPath('data.status', 'opted_in')
            ->assertJsonPath('data.source_domain', 'webinar.medcon.ae');

        $this->assertDatabaseHas('whatsapp_consents', [
            'phone' => '971566167139',
            'status' => 'opted_in',
            'source' => 'external_api',
            'source_domain' => 'webinar.medcon.ae',
            'evidence_reference' => 'teams-registration:123',
        ]);
        $this->assertDatabaseHas('whatsapp_consent_events', [
            'event' => 'opted_in',
            'source' => 'external_api',
            'evidence_reference' => 'teams-registration:123',
        ]);

        $this->postJson('/api/v1/whatsapp/consents', [
            'phone' => '+966 50 123 4567',
            'action' => 'opt_in',
            'source_domain' => 'events.medcon.ae',
            'consent_text' => 'I agree to receive Medcon updates through WhatsApp.',
        ])->assertOk()->assertJsonPath('data.phone', '966501234567');
    }

    public function test_external_consent_api_validates_input_and_deduplicates_retried_evidence(): void
    {
        $payload = [
            'phone' => '+971501234567',
            'action' => 'opt_in',
            'source_domain' => 'registration.medcon.ae',
            'consent_text' => 'I agree to receive Medcon updates through WhatsApp.',
            'evidence_reference' => 'registration:456',
        ];

        $this->postJson('/api/v1/whatsapp/consents', $payload)->assertOk();
        $this->postJson('/api/v1/whatsapp/consents', $payload)->assertOk();
        $this->assertDatabaseCount('whatsapp_consent_events', 1);

        $this->postJson('/api/v1/whatsapp/consents', [
            'phone' => '0',
            'action' => 'opt_in',
            'source_domain' => 'not a domain',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'phone', 'source_domain', 'consent_text',
        ]);
    }

    public function test_out_of_order_status_webhooks_do_not_downgrade_delivery_state(): void
    {
        WhatsAppMessage::create([
            'whatsapp_message_id' => 'wamid.status',
            'phone' => '971501234567',
            'direction' => 'outbound',
            'type' => 'template',
            'template_name' => 'hello_world',
            'template_language' => 'en_US',
            'status' => 'accepted',
        ]);

        $this->signedWebhook($this->statusPayload('delivered', '1710000200'))->assertOk();
        $this->signedWebhook($this->statusPayload('sent', '1710000100'))->assertOk();

        $message = WhatsAppMessage::where('whatsapp_message_id', 'wamid.status')->firstOrFail();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_administrator_can_send_a_test_template_without_exposing_the_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '971501234567', 'wa_id' => '971501234567']],
                'messages' => [['id' => 'wamid.outbound']],
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/test-message', [
                'to' => '+971 50 123 4567',
                'template_name' => 'hello_world',
                'language' => 'en_US',
            ])
            ->assertOk()
            ->assertJsonPath('data.whatsapp_message_id', 'wamid.outbound')
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_message_id' => 'wamid.outbound',
            'phone' => '971501234567',
            'direction' => 'outbound',
            'status' => 'accepted',
        ]);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v25.0/123456789/messages'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['to'] === '971501234567'
                && $request['template']['name'] === 'hello_world';
        });
    }

    public function test_administrator_can_send_template_parameters_and_an_image_header(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.parameterized']],
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/test-message', [
                'to' => '+971501234567',
                'template_name' => 'personalized_image_invitation',
                'language' => 'en_US',
                'header_image_url' => 'https://database.medcon.ae/images/event.png',
                'body_parameters' => ['Dr. Ahmad', 'Medcon Event'],
            ])
            ->assertOk()
            ->assertJsonPath('data.whatsapp_message_id', 'wamid.parameterized');

        Http::assertSent(function ($request) {
            $components = $request['template']['components'] ?? [];

            return $components[0]['type'] === 'header'
                && $components[0]['parameters'][0]['type'] === 'image'
                && $components[0]['parameters'][0]['image']['link'] === 'https://database.medcon.ae/images/event.png'
                && $components[1]['type'] === 'body'
                && $components[1]['parameters'][0]['text'] === 'Dr. Ahmad'
                && $components[1]['parameters'][1]['text'] === 'Medcon Event';
        });
    }

    public function test_administrator_can_create_a_text_template(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'template-123',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/templates', [
                'name' => 'medcon_event_invitation',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'header' => 'Medcon Event',
                'body' => 'We would like to invite you to our upcoming medical event.',
                'footer' => 'Reply STOP to opt out.',
                'buttons' => [
                    ['text' => 'Interested'],
                    ['text' => 'Not interested'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', 'template-123')
            ->assertJsonPath('data.status', 'PENDING');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v25.0/987654321/message_templates'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['name'] === 'medcon_event_invitation'
                && $request['components'][1]['type'] === 'BODY';
        });
    }

    public function test_administrator_can_create_a_template_with_a_url_button(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'template-with-url',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/templates', [
                'name' => 'event_with_link',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'body' => 'View the event details.',
                'buttons' => [
                    [
                        'type' => 'URL',
                        'text' => 'Event details',
                        'url' => 'https://example.com/event',
                    ],
                ],
            ])
            ->assertCreated();

        Http::assertSent(function ($request) {
            $button = $request['components'][1]['buttons'][0] ?? [];

            return $button === [
                'type' => 'URL',
                'text' => 'Event details',
                'url' => 'https://example.com/event',
            ];
        });
    }

    public function test_administrator_can_create_an_advanced_meta_native_template(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'authentication-template',
                'status' => 'PENDING',
                'category' => 'AUTHENTICATION',
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $components = [
            ['type' => 'BODY', 'add_security_recommendation' => true],
            ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
            [
                'type' => 'BUTTONS',
                'buttons' => [[
                    'type' => 'OTP',
                    'otp_type' => 'COPY_CODE',
                    'text' => 'Copy code',
                ]],
            ],
        ];

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/templates', [
                'name' => 'login_code',
                'language' => 'en_US',
                'category' => 'AUTHENTICATION',
                'message_send_ttl_seconds' => 600,
                'components' => $components,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'AUTHENTICATION');

        Http::assertSent(function ($request) {
            return $request['category'] === 'AUTHENTICATION'
                && (int) $request['message_send_ttl_seconds'] === 600
                && $request['components'][0]['add_security_recommendation'] === true
                && $request['components'][2]['buttons'][0]['otp_type'] === 'COPY_CODE';
        });
    }

    public function test_administrator_can_retrieve_quick_reply_responses(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $this->signedWebhook($this->interactiveReplyPayload(
            'wamid.reply',
            'interested',
            'Interested'
        ))->assertOk();

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson('/api/whatsapp/responses')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Interested')
            ->assertJsonPath('data.0.metadata.button_reply_id', 'interested')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_responses_can_be_filtered_by_attributed_template_and_language(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Cardiology Webinar Campaign',
            'template_name' => 'webinar_registration',
            'template_language' => 'en_US',
            'status' => 'completed',
            'require_opt_in' => false,
            'recipient_count' => 1,
            'created_by' => $administrator->id,
        ]);
        WhatsAppMessage::create([
            'whatsapp_campaign_id' => $campaign->id,
            'whatsapp_message_id' => 'wamid.webinar-template',
            'phone' => '971501234567',
            'direction' => 'outbound',
            'type' => 'template',
            'template_name' => 'webinar_registration',
            'template_language' => 'en_US',
            'status' => 'delivered',
        ]);
        WhatsAppMessage::create([
            'whatsapp_message_id' => 'wamid.webinar-response',
            'reply_to_whatsapp_message_id' => 'wamid.webinar-template',
            'phone' => '971501234567',
            'direction' => 'inbound',
            'type' => 'button',
            'status' => 'received',
            'content' => 'Yes',
            'received_at' => now(),
        ]);
        WhatsAppMessage::create([
            'whatsapp_message_id' => 'wamid.unattributed-response',
            'phone' => '971501234568',
            'direction' => 'inbound',
            'type' => 'button',
            'status' => 'received',
            'content' => 'webinar_registration',
            'received_at' => now(),
        ]);

        $token = JWTAuth::fromUser($administrator);
        $this->withToken($token)
            ->getJson('/api/whatsapp/responses?template_name=webinar_registration&template_language=en_US')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.content', 'Yes')
            ->assertJsonPath('data.0.template_name', 'webinar_registration')
            ->assertJsonPath('data.0.template_language', 'en_US')
            ->assertJsonPath('data.0.campaign.id', $campaign->id)
            ->assertJsonPath('data.0.campaign.name', 'Cardiology Webinar Campaign');

        $this->withToken($token)
            ->getJson('/api/whatsapp/responses?template_name=webinar_registration&template_language=ar')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        $export = $this->withToken($token)
            ->get('/api/whatsapp/responses/export?template_name=webinar_registration&template_language=en_US');
        $export->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $this->assertSpreadsheetContains($path, 'Responses', 'B2', 'webinar_registration', 'E2', 'Cardiology Webinar Campaign');
        $this->assertSame(2, IOFactory::load($path)->getActiveSheet()->getHighestDataRow());

        $this->withToken($token)
            ->getJson('/api/whatsapp/responses?template_name=Webinar Registration!')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template_name');
        $this->withToken($token)
            ->getJson('/api/whatsapp/responses/export?template_language=english_us')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template_language');
    }

    public function test_administrator_can_create_a_personalized_campaign_snapshot(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'id' => 'template-test2', 'name' => 'test2', 'language' => 'en_US',
            'status' => 'APPROVED', 'category' => 'UTILITY',
        ]]])]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $contact = CSVData::create([
            'firstname' => 'Majd', 'lastname' => 'Ahmad', 'email' => 'campaign@example.test',
            'phone' => '056 616 7139', 'country' => 'UAE', 'user_id' => $administrator->id,
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))->postJson('/api/whatsapp/campaigns', [
            'name' => 'Conference invitation',
            'template_name' => 'test2',
            'template_language' => 'en_US',
            'template_category' => 'MARKETING',
            'require_opt_in' => false,
            'contact_ids' => [$contact->id],
            'components' => [[
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => '{{contact.firstname}}']],
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.recipient_count', 1);

        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'phone' => '971566167139', 'firstname' => 'Majd', 'status' => 'pending',
        ]);
        $recipient = WhatsAppCampaignRecipient::firstOrFail();
        $this->assertSame('Majd', $recipient->components[0]['parameters'][0]['text']);
    }

    public function test_campaign_audience_preview_unions_tags_and_contacts_without_duplicate_phones(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'id' => 'template-preview', 'name' => 'preview_template', 'language' => 'en_US',
            'status' => 'APPROVED', 'category' => 'UTILITY',
        ]]])]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $first = CSVData::create(['firstname' => 'One', 'email' => 'one@example.test', 'phone' => '+971501111111', 'user_id' => $administrator->id]);
        $duplicate = CSVData::create(['firstname' => 'Two', 'email' => 'two@example.test', 'phone' => '971 50 111 1111', 'user_id' => $administrator->id]);
        $invalid = CSVData::create(['firstname' => 'Three', 'email' => 'three@example.test', 'phone' => null, 'user_id' => $administrator->id]);
        $tagId = DB::table('tags')->insertGetId(['name' => 'Conference', 'slug' => 'conference', 'created_by' => $administrator->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('csv_data_tag')->insert([
            ['tag_id' => $tagId, 'csv_data_id' => $first->id, 'created_at' => now(), 'updated_at' => now()],
            ['tag_id' => $tagId, 'csv_data_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/campaigns/audience-preview', [
                'template_name' => 'preview_template', 'template_language' => 'en_US',
                'tag_ids' => [$tagId], 'contact_ids' => [$invalid->id], 'require_opt_in' => false,
            ])->assertOk()
            ->assertJsonPath('data.selected_contact_count', 3)
            ->assertJsonPath('data.unique_valid_phone_count', 1)
            ->assertJsonPath('data.eligible_recipient_count', 1)
            ->assertJsonPath('data.excluded.duplicate_phone_count', 1)
            ->assertJsonPath('data.excluded.invalid_or_missing_phone_count', 1)
            ->assertJsonPath('data.tag_breakdown.0.unique_valid_phone_count', 1);
    }

    public function test_draft_campaign_can_be_edited_and_rebuilds_its_recipient_snapshot(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'id' => 'template-edit', 'name' => 'edit_template', 'language' => 'en_US',
            'status' => 'APPROVED', 'category' => 'UTILITY',
        ]]])]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $first = CSVData::create(['firstname' => 'First', 'email' => 'edit-first@example.test', 'phone' => '+971501111111', 'user_id' => $administrator->id]);
        $second = CSVData::create(['firstname' => 'Second', 'email' => 'edit-second@example.test', 'phone' => '+971502222222', 'user_id' => $administrator->id]);
        $tagId = DB::table('tags')->insertGetId(['name' => 'Cardiologists', 'slug' => 'cardiologists', 'created_by' => $administrator->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('csv_data_tag')->insert(['tag_id' => $tagId, 'csv_data_id' => $second->id, 'created_at' => now(), 'updated_at' => now()]);
        $token = JWTAuth::fromUser($administrator);

        $created = $this->withToken($token)->postJson('/api/whatsapp/campaigns', [
            'name' => 'Original', 'template_name' => 'edit_template', 'template_language' => 'en_US',
            'contact_ids' => [$first->id], 'require_opt_in' => false,
        ])->assertCreated();
        $campaignId = $created->json('data.id');

        $this->withToken($token)->patchJson("/api/whatsapp/campaigns/{$campaignId}", [
            'name' => 'Updated campaign', 'contact_ids' => [], 'tag_ids' => [$tagId],
            'require_opt_in' => false,
            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '{{contact.firstname}}']]]],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated campaign')
            ->assertJsonPath('data.recipient_count', 1);

        $this->assertDatabaseMissing('whatsapp_campaign_recipients', ['whatsapp_campaign_id' => $campaignId, 'csv_data_id' => $first->id]);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', ['whatsapp_campaign_id' => $campaignId, 'csv_data_id' => $second->id, 'firstname' => 'Second']);
        $this->withToken($token)->getJson("/api/whatsapp/campaigns/{$campaignId}")
            ->assertOk()
            ->assertJsonPath('data.campaign.contact_ids', [])
            ->assertJsonPath('data.campaign.tag_ids.0', $tagId)
            ->assertJsonPath('data.campaign.tags.0.name', 'Cardiologists')
            ->assertJsonPath('data.campaign.tags.0.contacts_count', 1)
            ->assertJsonPath('data.campaign.recipient_count', 1)
            ->assertJsonPath('data.analytics.eligible_recipient_count', 1);

        WhatsAppCampaign::findOrFail($campaignId)->update(['status' => 'sending']);
        $this->withToken($token)->patchJson("/api/whatsapp/campaigns/{$campaignId}", ['name' => 'Too late'])
            ->assertStatus(409)->assertJsonPath('code', 'WHATSAPP_CAMPAIGN_ALREADY_STARTED');
    }

    public function test_scheduled_send_waits_until_the_scheduled_time(): void
    {
        Queue::fake();
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Scheduled', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'draft', 'require_opt_in' => false, 'recipient_count' => 1,
            'contact_ids' => [], 'tag_ids' => [], 'created_by' => $administrator->id,
        ]);
        $campaign->recipients()->create(['phone' => '971566167139']);
        $scheduledAt = now()->addHours(2);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson("/api/whatsapp/campaigns/{$campaign->id}/send", ['scheduled_at' => 'not-a-date'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson("/api/whatsapp/campaigns/{$campaign->id}/send", ['scheduled_at' => $scheduledAt->toIso8601String()])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'scheduled');
        Queue::assertNothingPushed();

        $this->artisan('whatsapp:dispatch-scheduled-campaigns')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travelTo($scheduledAt->copy()->addSecond());
        $this->artisan('whatsapp:dispatch-scheduled-campaigns')->assertSuccessful();
        $this->assertSame('sending', $campaign->fresh()->status);
        Queue::assertPushed(SendWhatsAppCampaignRecipient::class, 1);
        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson("/api/whatsapp/campaigns/{$campaign->id}/send", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('code', 'WHATSAPP_CAMPAIGN_CANNOT_BE_SCHEDULED');
    }

    public function test_immediate_send_queues_pending_recipients_without_a_schedule(): void
    {
        Queue::fake();
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Immediate', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'draft', 'require_opt_in' => false, 'recipient_count' => 1,
            'created_by' => $administrator->id,
        ]);
        $recipient = $campaign->recipients()->create(['phone' => '971566167139']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson("/api/whatsapp/campaigns/{$campaign->id}/send", [])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'sending')
            ->assertJsonPath('data.scheduled_at', null);
        Queue::assertPushed(
            SendWhatsAppCampaignRecipient::class,
            fn (SendWhatsAppCampaignRecipient $job) => $job->recipientId === $recipient->id
        );
    }

    public function test_campaign_upload_signature_enforces_the_database_campaign_folder(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $response = $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/campaigns/cloudinary-signature', [
                'campaign_name' => 'October Surgery Conference 2026',
            ])->assertOk()
            ->assertJsonPath('data.folder', 'database/campaigns/october-surgery-conference-2026')
            ->assertJsonPath('data.upload_parameters.asset_folder', 'database/campaigns/october-surgery-conference-2026')
            ->assertJsonPath('data.cloud_name', 'medcon-cloud')
            ->assertJsonPath('data.api_key', 'cloudinary-key');

        $this->assertArrayNotHasKey('api_secret', $response->json('data'));
        $this->assertNotEmpty($response->json('data.signature'));
    }

    public function test_draft_campaign_cloudinary_media_can_be_updated_before_sending(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Editable media', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'draft', 'require_opt_in' => false, 'created_by' => $administrator->id,
        ]);
        $recipient = $campaign->recipients()->create(['phone' => '971566167139', 'firstname' => 'Majd']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->patchJson("/api/whatsapp/campaigns/{$campaign->id}/content", ['components' => [[
                'type' => 'header', 'parameters' => [[
                    'type' => 'image', 'image' => ['link' => 'https://res.cloudinary.com/medcon/image/upload/conference.png'],
                ]],
            ], [
                'type' => 'body', 'parameters' => [['type' => 'text', 'text' => '{{contact.firstname}}']],
            ]]])->assertOk();

        $updated = $recipient->fresh()->components;
        $this->assertSame('https://res.cloudinary.com/medcon/image/upload/conference.png', $updated[0]['parameters'][0]['image']['link']);
        $this->assertSame('Majd', $updated[1]['parameters'][0]['text']);
    }

    public function test_quick_reply_is_attributed_to_the_originating_campaign(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Conference', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'sending', 'require_opt_in' => false, 'created_by' => $administrator->id,
        ]);
        $recipient = $campaign->recipients()->create(['phone' => '971566167139']);
        $outbound = WhatsAppMessage::create([
            'whatsapp_campaign_id' => $campaign->id,
            'whatsapp_campaign_recipient_id' => $recipient->id,
            'whatsapp_message_id' => 'wamid.campaign-origin', 'phone' => '971566167139',
            'direction' => 'outbound', 'type' => 'template', 'status' => 'accepted',
        ]);
        $recipient->update(['whatsapp_message_record_id' => $outbound->id, 'status' => 'accepted']);

        $payload = $this->interactiveReplyPayload('wamid.campaign-reply', 'attending', 'I will attend');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['context'] = [
            'from' => '15551717511', 'id' => 'wamid.campaign-origin',
        ];
        $this->signedWebhook($payload)->assertOk();

        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_message_id' => 'wamid.campaign-reply',
            'whatsapp_campaign_id' => $campaign->id,
            'reply_to_whatsapp_message_id' => 'wamid.campaign-origin',
            'content' => 'I will attend',
        ]);
        $this->assertSame(1, $recipient->fresh()->response_count);
    }

    public function test_administrator_can_continue_chat_inside_the_customer_service_window(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.chat']]])]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        WhatsAppMessage::create([
            'whatsapp_message_id' => 'wamid.inbound-chat', 'phone' => '971566167139',
            'direction' => 'inbound', 'type' => 'text', 'status' => 'received',
            'content' => 'Hello', 'received_at' => now()->subHour(),
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/conversations/971566167139/messages', ['message' => 'How can we help?'])
            ->assertCreated()->assertJsonPath('data.whatsapp_message_id', 'wamid.chat');
    }

    public function test_conversation_contract_returns_the_newest_page_in_chronological_order(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        foreach (range(1, 3) as $number) {
            WhatsAppMessage::create([
                'whatsapp_message_id' => 'wamid.conversation-'.$number,
                'phone' => '971566167139',
                'direction' => 'inbound',
                'type' => 'text',
                'status' => 'received',
                'content' => 'Message '.$number,
                'received_at' => now()->subMinutes(4 - $number),
            ]);
        }

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson('/api/whatsapp/conversations/0566167139?per_page=2')
            ->assertOk()
            ->assertJsonPath('conversation.phone', '971566167139')
            ->assertJsonPath('conversation.can_send_free_form', true)
            ->assertJsonStructure(['conversation' => ['customer_service_window_expires_at']])
            ->assertJsonPath('data.0.content', 'Message 2')
            ->assertJsonPath('data.1.content', 'Message 3');
    }

    public function test_free_form_message_is_rejected_when_the_customer_service_window_is_closed(): void
    {
        Http::fake();
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        WhatsAppMessage::create([
            'whatsapp_message_id' => 'wamid.old-inbound',
            'phone' => '971566167139',
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'received',
            'content' => 'Old message',
            'received_at' => now()->subHours(25),
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/conversations/971566167139/messages', ['message' => 'Hello'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'WHATSAPP_WINDOW_CLOSED');

        Http::assertNothingSent();
    }

    public function test_campaign_analytics_use_delivered_and_read_as_the_cumulative_denominator(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Analytics', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'completed', 'require_opt_in' => false, 'recipient_count' => 2,
            'created_by' => $administrator->id,
        ]);
        $campaign->recipients()->create(['phone' => '971500000001', 'status' => 'read', 'response_count' => 1]);
        $campaign->recipients()->create(['phone' => '971500000002', 'status' => 'delivered', 'response_count' => 0]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson("/api/whatsapp/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.status_counts.read', 1)
            ->assertJsonPath('data.status_counts.delivered', 1)
            ->assertJsonPath('data.analytics.eligible_recipient_count', 2)
            ->assertJsonPath('data.analytics.successfully_delivered_count', 2)
            ->assertJsonPath('data.analytics.responded_count', 1)
            ->assertJsonPath('data.analytics.response_rate', 50)
            ->assertJsonPath('data.status_count_semantics.mutually_exclusive', true)
            ->assertJsonPath('data.status_count_semantics.read_included_in_delivered_bucket', false);
    }

    public function test_administrator_can_export_all_filtered_whatsapp_records_as_xlsx(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $contact = CSVData::create([
            'firstname' => 'Export', 'lastname' => 'Doctor', 'email' => 'export@example.test',
            'phone' => '+971501234567', 'user_id' => $administrator->id,
        ]);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Export Campaign', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'completed', 'require_opt_in' => false, 'recipient_count' => 1,
            'created_by' => $administrator->id,
        ]);
        $campaign->recipients()->create([
            'csv_data_id' => $contact->id, 'phone' => '971501234567', 'firstname' => 'Export',
            'lastname' => 'Doctor', 'email' => 'export@example.test', 'status' => 'read',
            'response_count' => 1, 'last_response_content' => 'Yes', 'last_responded_at' => now(),
        ]);
        WhatsAppMessage::create([
            'csv_data_id' => $contact->id, 'whatsapp_campaign_id' => $campaign->id,
            'whatsapp_message_id' => 'wamid.export-response', 'phone' => '971501234567',
            'direction' => 'inbound', 'type' => 'button', 'status' => 'received',
            'content' => 'Yes', 'received_at' => now(),
        ]);

        $token = JWTAuth::fromUser($administrator);
        $general = $this->withToken($token)->get('/api/whatsapp/responses/export?phone=0501234567');
        $general->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('whatsapp-responses-', $general->headers->get('content-disposition'));
        $this->assertSpreadsheetContains($general->baseResponse->getFile()->getPathname(), 'Responses', 'L1', 'Received Time', 'J2', 'Yes');

        $campaignResponses = $this->withToken($token)->get("/api/whatsapp/campaigns/{$campaign->id}/responses/export");
        $campaignResponses->assertOk();
        $this->assertSpreadsheetContains($campaignResponses->baseResponse->getFile()->getPathname(), 'Responses', 'B2', 'test2', 'E2', 'Export Campaign');

        $recipients = $this->withToken($token)->get("/api/whatsapp/campaigns/{$campaign->id}/recipients/export?status=read&responded=1");
        $recipients->assertOk();
        $this->assertSpreadsheetContains($recipients->baseResponse->getFile()->getPathname(), 'Recipients', 'F2', 'read', 'H2', 'Yes');
    }

    public function test_whatsapp_exports_preserve_administrator_authorization_and_filter_validation(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Protected Export', 'template_name' => 'test2', 'template_language' => 'en_US',
            'status' => 'draft', 'require_opt_in' => false, 'recipient_count' => 0,
            'created_by' => $administrator->id,
        ]);

        $this->getJson('/api/whatsapp/responses/export')->assertUnauthorized();
        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson("/api/whatsapp/campaigns/{$campaign->id}/recipients/export?status=not-real")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_non_administrator_cannot_export_whatsapp_reports(): void
    {
        $regularUser = User::factory()->create(['access_type' => 'R']);

        $this->withToken(JWTAuth::fromUser($regularUser))
            ->getJson('/api/whatsapp/responses/export')
            ->assertForbidden();
    }

    public function test_administrator_can_list_templates(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => 'template-123',
                    'name' => 'medcon_event_invitation',
                    'status' => 'APPROVED',
                    'category' => 'MARKETING',
                    'language' => 'en_US',
                ]],
                'paging' => ['cursors' => ['after' => 'next-cursor']],
            ]),
        ]);
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson('/api/whatsapp/templates?limit=25')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'APPROVED')
            ->assertJsonPath('paging.cursors.after', 'next-cursor');
    }

    public function test_non_administrator_cannot_manage_templates(): void
    {
        $regularUser = User::factory()->create(['access_type' => 'R']);

        $this->withToken(JWTAuth::fromUser($regularUser))
            ->postJson('/api/whatsapp/templates', [
                'name' => 'not_allowed',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'body' => 'Not allowed.',
            ])
            ->assertForbidden();
    }

    public function test_administrator_can_create_an_image_template_with_body_parameters(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/111222333/uploads?')) {
                return Http::response(['id' => 'upload:template-image']);
            }

            if (str_ends_with($request->url(), '/987654321/message_templates')) {
                return Http::response([
                    'id' => 'template-with-image',
                    'status' => 'PENDING',
                    'category' => 'MARKETING',
                ]);
            }

            return Http::response(['h' => 'meta-private-image-handle']);
        });
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='
        );
        $image = UploadedFile::fake()->createWithContent('header.png', $png);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->post('/api/whatsapp/templates', [
                'name' => 'personalized_image_invitation',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'header_type' => 'IMAGE',
                'header_image' => $image,
                'body' => 'Hello {{1}}, you are invited to {{2}}.',
                'body_examples' => ['Dr. Ahmad', 'Medcon Event'],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.id', 'template-with-image');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/987654321/message_templates')) {
                return false;
            }

            return $request['components'][0]['format'] === 'IMAGE'
                && $request['components'][0]['example']['header_handle'][0] === 'meta-private-image-handle'
                && $request['components'][1]['example']['body_text'][0] === ['Dr. Ahmad', 'Medcon Event'];
        });
    }

    public function test_template_parameters_require_matching_examples(): void
    {
        Http::fake();
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/whatsapp/templates', [
                'name' => 'missing_examples',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'body' => 'Hello {{1}}, your appointment is {{2}}.',
                'body_examples' => ['Dr. Ahmad'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_examples');

        Http::assertNothingSent();
    }

    private function signedWebhook(array $payload, string $secret = self::APP_SECRET)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $json, $secret);

        return $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $json
        );
    }

    private function assertSpreadsheetContains(string $path, string $expectedSheetName, string $firstCell, $firstValue, ?string $secondCell = null, $secondValue = null): void
    {
        $spreadsheet = IOFactory::load($path);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame($expectedSheetName, $sheet->getTitle());
        $this->assertSame($firstValue, $sheet->getCell($firstCell)->getValue());
        if ($secondCell !== null) {
            $this->assertSame($secondValue, $sheet->getCell($secondCell)->getValue());
        }
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertTrue($sheet->getAutoFilter()->getRange() !== '');
    }

    private function inboundPayload(string $messageId, string $text, string $timestamp = '1710000000'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '987654321',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'messages' => [[
                            'from' => '971566167139',
                            'id' => $messageId,
                            'timestamp' => $timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function statusPayload(string $status, string $timestamp): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '987654321',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => 'wamid.status',
                            'status' => $status,
                            'timestamp' => $timestamp,
                            'recipient_id' => '971501234567',
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function interactiveReplyPayload(string $messageId, string $replyId, string $title): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '987654321',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'messages' => [[
                            'from' => '971566167139',
                            'id' => $messageId,
                            'timestamp' => '1710000000',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'button_reply',
                                'button_reply' => [
                                    'id' => $replyId,
                                    'title' => $title,
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->string('access_type')->default('R');
            $table->boolean('receive_notification')->default(false);
            $table->timestamps();
        });

        Schema::create('csv_data', function (Blueprint $table) {
            $table->id();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->foreignId('user_id');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });
        Schema::create('csv_data_tag', function (Blueprint $table) {
            $table->foreignId('csv_data_id');
            $table->foreignId('tag_id');
            $table->timestamps();
        });

        Schema::create('whatsapp_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_data_id')->nullable();
            $table->string('phone', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->string('scope', 50)->default('marketing');
            $table->string('source', 50);
            $table->string('source_domain', 255)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 30)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('template_name');
            $table->string('template_language');
            $table->string('template_category')->nullable();
            $table->json('components')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('require_opt_in')->default(true);
            $table->json('contact_ids')->nullable();
            $table->json('tag_ids')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('excluded_no_phone_count')->default(0);
            $table->unsignedInteger('excluded_duplicate_phone_count')->default(0);
            $table->unsignedInteger('excluded_no_consent_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_data_id')->nullable();
            $table->foreignId('whatsapp_campaign_id')->nullable();
            $table->foreignId('whatsapp_campaign_recipient_id')->nullable();
            $table->string('whatsapp_message_id', 255)->nullable()->unique();
            $table->string('reply_to_whatsapp_message_id')->nullable();
            $table->string('phone', 32);
            $table->string('direction', 10);
            $table->string('type', 30)->default('text');
            $table->string('template_name', 150)->nullable();
            $table->string('template_language', 20)->nullable();
            $table->string('status', 30);
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_campaign_id');
            $table->foreignId('csv_data_id')->nullable();
            $table->string('phone');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->json('components')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('whatsapp_message_record_id')->nullable();
            $table->unsignedInteger('response_count')->default(0);
            $table->text('last_response_content')->nullable();
            $table->timestamp('last_responded_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_consent_id');
            $table->foreignId('whatsapp_message_record_id')->nullable();
            $table->string('event', 30);
            $table->string('source', 50);
            $table->string('evidence_reference', 255)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }
}
