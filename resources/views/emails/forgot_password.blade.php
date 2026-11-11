@extends('emails.layouts.medcon', [
    'emailTitle' => 'Reset your Medcon Database password',
    'preheader' => 'Use this secure link to choose a new password.',
])

@section('content')
    <p class="eyebrow">Password assistance</p>
    <h1>Reset your password</h1>
    <p>Hello {{ $user_name }},</p>
    <p>We received a request to reset the password for your Medcon Database account.</p>

    <p style="text-align: center;"><a href="{{ $reset_url }}" class="button">Reset my password</a></p>

    <div class="notice">This secure link expires in {{ $expires_minutes }} minutes and can only be used once. If you did not request a password reset, you can safely ignore this email.</div>
    <p style="font-size: 12px; word-break: break-all;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $reset_url }}">{{ $reset_url }}</a></p>
@endsection
