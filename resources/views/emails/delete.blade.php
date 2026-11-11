@extends('emails.layouts.medcon', ['emailTitle' => 'Contact deleted', 'preheader' => 'A contact was soft-deleted in Medcon Database.'])

@section('content')
    <p class="eyebrow">Contact activity</p>
    <h1>Contact deleted</h1>
    <p>A contact was moved to the deleted state. Authorized users can restore it from Medcon Database.</p>
    <div class="panel">
        <p><span class="label">Performed by</span><br><span class="value">{{ $user_name }} · {{ $user_email }}</span></p>
        <p><span class="label">Deletion time</span><br><span class="value">{{ $update_time }} (Dubai)</span></p>
        <p><span class="label">Contact</span><br><span class="value">{{ $data_summary }}</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}" class="button">Review deleted contacts</a></p>
@endsection
