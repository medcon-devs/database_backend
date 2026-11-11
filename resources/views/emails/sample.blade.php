@extends('emails.layouts.medcon', ['emailTitle' => $title ?? 'Email test', 'preheader' => $content ?? 'Medcon Database email test.'])

@section('content')
    <p class="eyebrow">System check</p>
    <h1>{{ $title ?? 'Email delivery test' }}</h1>
    <p>{{ $content ?? 'This message confirms that Medcon Database email delivery is working.' }}</p>
    <div class="notice">No action is required.</div>
@endsection
