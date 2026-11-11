@extends('emails.layouts.medcon', ['emailTitle' => 'Profile updated', 'preheader' => 'A Medcon Database user profile was updated.'])

@section('content')
    <p class="eyebrow">User administration</p>
    <h1>User profile updated</h1>
    <p>The following account details or permissions were updated.</p>
    <div class="panel">
        <p><span class="label">User</span><br><span class="value">{{ $user_name }}</span></p>
        <p><span class="label">Email</span><br><span class="value">{{ $user_email }}</span></p>
        <p><span class="label">Access level</span><br><span class="badge">{{ $access_label }}</span></p>
        <p><span class="label">Updated</span><br><span class="value">{{ $updated_time }} (Dubai)</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}/admin" class="button">Review user profile</a></p>
@endsection
