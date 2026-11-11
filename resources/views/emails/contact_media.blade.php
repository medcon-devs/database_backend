@extends('emails.layouts.medcon', ['emailTitle' => 'Contact file activity', 'preheader' => 'A contact file was changed in Medcon Database.'])

@section('content')
    <p class="eyebrow">Contact activity</p>
    <h1>Contact file {{ $action }}</h1>
    <p>{{ $user_name }} {{ $action }} a file for {{ $contact_name ?: 'a contact' }} at {{ $action_time }} (Dubai).</p>
    <div class="details-card">
        <p><span class="label">Contact</span><br><span class="value">{{ $contact_name ?: '—' }}</span></p>
        <p><span class="label">Contact email</span><br><span class="value">{{ $contact_email ?: '—' }}</span></p>
        <p><span class="label">File title</span><br><span class="value">{{ $media_title ?: '—' }}</span></p>
        <p><span class="label">File name</span><br><span class="value">{{ $file_name ?: '—' }}</span></p>
        <p><span class="label">Note</span><br><span class="value">{{ $note ?: '—' }}</span></p>
    </div>
    <p class="hint">Performed by {{ $user_email }}</p>
@endsection
