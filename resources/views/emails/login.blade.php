@extends('emails.layouts.medcon', ['emailTitle' => 'Sign-in notification', 'preheader' => 'A user signed in to Medcon Database.'])

@section('content')
    <p class="eyebrow">Security notification</p>
    <h1>New account sign-in</h1>
    <p>A user successfully signed in to Medcon Database. Review the details below.</p>
    <div class="panel">
        <p><span class="label">User</span><br><span class="value">{{ $user_name }}</span></p>
        <p><span class="label">Email</span><br><span class="value">{{ $user_email }}</span></p>
        <p><span class="label">Access level</span><br><span class="badge">{{ $access_label }}</span></p>
        <p><span class="label">Sign-in time</span><br><span class="value">{{ $login_time }} (Dubai)</span></p>
    </div>
    <div class="notice">If this activity is unexpected, review the account immediately and revoke its access.</div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}/admin" class="button">Open user administration</a></p>
@endsection
