@extends('emails.layouts.medcon')

@section('title', 'You were mentioned in a sticky note')

@section('content')
    <h1>You were mentioned in a sticky note</h1>
    <p>Hello {{ $recipient_name }},</p>
    <p><strong>{{ $sender_name }}</strong> mentioned you in a sticky note.</p>
    <p><strong>Doctor/contact:</strong> {{ $contact_name ?: 'Not specified' }}<br>
       <strong>Location:</strong> {{ $location_label }}<br>
       <strong>Date:</strong> {{ $mentioned_at->timezone('Asia/Dubai')->format('d M Y, H:i') }}</p>
    <div style="margin: 20px 0; padding: 16px; background: #f5f7fa; border-left: 4px solid #2563eb; white-space: pre-wrap;">{{ $note_content }}</div>
    <p><a href="{{ $destination_url }}" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;">Open the note</a></p>
    <p>If the button does not work, copy and paste this link:</p>
    <p style="word-break:break-all;"><a href="{{ $destination_url }}">{{ $destination_url }}</a></p>
@endsection
