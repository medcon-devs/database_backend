@extends('auth.layout')

@section('title', 'Password link unavailable')

@section('content')
    <h1>This password link is no longer valid</h1>
    <p class="lead">For your security, password links expire after 60 minutes and can only be used once.</p>
    <div class="error" role="alert">
        {{ $mode === 'reset'
            ? 'Return to login and request a new password reset link.'
            : 'Ask your Medcon Database administrator to resend your account invitation.' }}
    </div>
    <a class="button" href="{{ config('app.frontend_login_url') }}">Return to login</a>
@endsection
