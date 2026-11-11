@extends('emails.layouts.medcon', ['emailTitle' => 'Account status changed', 'preheader' => 'A Medcon Database account status was updated.'])

@section('content')
    <p class="eyebrow">Access control</p>
    <h1>Account {{ $is_banned ? 'suspended' : 'restored' }}</h1>
    <p>The access status for the following Medcon Database user has changed.</p>
    <div class="panel">
        <p><span class="label">User</span><br><span class="value">{{ $user_name }}</span></p>
        <p><span class="label">Email</span><br><span class="value">{{ $user_email }}</span></p>
        <p><span class="label">Current status</span><br><span class="badge {{ $is_banned ? 'badge-danger' : 'badge-success' }}">{{ $is_banned ? 'Suspended' : 'Active' }}</span></p>
        <p><span class="label">Updated</span><br><span class="value">{{ $updated_time }} (Dubai)</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}/admin" class="button">Review user access</a></p>
@endsection
