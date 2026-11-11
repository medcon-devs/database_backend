@extends('emails.layouts.medcon', ['emailTitle' => 'New user created', 'preheader' => 'A new user was added to Medcon Database.'])

@section('content')
    <p class="eyebrow">User administration</p>
    <h1>New user created</h1>
    <p>A new account has been added to Medcon Database.</p>
    <div class="panel">
        <p><span class="label">User</span><br><span class="value">{{ $user_name }}</span></p>
        <p><span class="label">Email</span><br><span class="value">{{ $user_email }}</span></p>
        <p><span class="label">Access level</span><br><span class="badge">{{ $access_label }}</span></p>
        <p><span class="label">Created</span><br><span class="value">{{ $registered_time }} (Dubai)</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}/admin" class="button">Review user</a></p>
@endsection
