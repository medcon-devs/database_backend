@extends('emails.layouts.medcon', [
    'emailTitle' => 'Welcome to Medcon Database',
    'preheader' => 'Set your password to activate your Medcon Database account.',
])

@section('content')
    <p class="eyebrow">Account invitation</p>
    <h1>Welcome to Medcon Database</h1>
    <p>Hello {{ $user_name }},</p>
    <p>Your Medcon Database account has been created. Use the secure button below to choose your password and activate access.</p>

    <div class="panel">
        <p><span class="label">Email</span><br><span class="value">{{ $user_email }}</span></p>
        <p><span class="label">Access level</span><br><span class="badge">{{ $access_label }}</span></p>
    </div>

    <p style="text-align: center;"><a href="{{ $setup_url }}" class="button">Set my password</a></p>

    <div class="notice">For your security, this link expires in {{ $expires_minutes }} minutes and can only be used once. If you did not expect this invitation, contact Medcon Support.</div>
    <p style="font-size: 12px; word-break: break-all;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $setup_url }}">{{ $setup_url }}</a></p>
@endsection

