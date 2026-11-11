@extends('emails.layouts.medcon', [
    'emailTitle' => 'Administrator notifications are enabled',
    'preheader' => 'You will receive Medcon Database activity notifications.',
])

@section('content')
    <p class="eyebrow">Administrator preferences</p>
    <h1>Activity notifications are enabled</h1>
    <p>Hello {{ $user_name }},</p>
    <p>Your administrator account is now verified. You will receive email notifications for Medcon Database activity, including imports, exports, contact updates, deletions, restores, sign-ins, and user account changes.</p>

    <div class="notice">Only verified administrators who have notifications enabled receive these messages.</div>

    <p>If you prefer not to receive activity notifications, you can turn them off from your account settings at any time.</p>
    <p style="text-align: center;"><a href="{{ $settings_url }}" class="button">Manage notification settings</a></p>
@endsection
