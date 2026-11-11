<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    public function test_all_email_templates_render_with_the_shared_professional_layout(): void
    {
        $data = [
            'title' => 'Email test',
            'content' => 'Test message',
            'user_name' => 'Test User',
            'user_email' => 'user@example.com',
            'access_label' => 'Read and edit',
            'login_time' => '2026-07-14 12:00:00',
            'updated_time' => '2026-07-14 12:00:00',
            'registered_time' => '2026-07-14 12:00:00',
            'entry_time' => '2026-07-14 12:00:00',
            'restore_time' => '2026-07-14 12:00:00',
            'update_time' => '2026-07-14 12:00:00',
            'export_time' => '2026-07-14 12:00:00',
            'is_banned' => false,
            'data_summary' => 'One contact processed',
            'export_count' => 1,
            'old_firstname' => 'Old',
            'old_lastname' => 'Name',
            'old_email' => 'old@example.com',
            'old_phone' => '+971500000001',
            'old_country' => 'United Arab Emirates',
            'old_speciality' => 'Cardiology',
            'new_firstname' => 'New',
            'new_lastname' => 'Name',
            'new_email' => 'new@example.com',
            'new_phone' => '+971500000002',
            'new_country' => 'United Arab Emirates',
            'new_speciality' => 'Cardiology',
            'setup_url' => 'https://api.example.com/set-password/token?email=user@example.com',
            'reset_url' => 'https://api.example.com/set-password/token?email=user@example.com&mode=reset',
            'settings_url' => 'https://app.example.com/settings',
            'expires_minutes' => 60,
        ];

        $views = [
            'emails.ban_status',
            'emails.administrator_notifications_enabled',
            'emails.delete',
            'emails.export',
            'emails.forgot_password',
            'emails.login',
            'emails.new_user',
            'emails.profile_update',
            'emails.restore',
            'emails.sample',
            'emails.update',
            'emails.upload',
            'emails.welcome_user',
        ];

        foreach ($views as $view) {
            $html = view($view, $data)->render();

            $this->assertStringContainsString('Medcon Database', $html, "Failed rendering {$view}");
            $this->assertStringContainsString('Medcon Support', $html, "Shared layout missing from {$view}");
        }
    }
}
