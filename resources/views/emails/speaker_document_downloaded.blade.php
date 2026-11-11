@extends('emails.layouts.medcon')
@section('content')
<h1>Sensitive speaker document downloaded</h1>
<p>Hello {{ $manager_name }},</p>
<p>A restricted document was downloaded from the Speakers Directory.</p>
<div class="panel">
    <p><span class="label">Speaker</span><br><span class="value">{{ $speaker_name }}</span></p>
    <p><span class="label">Document</span><br><span class="value">{{ $document_name }}</span></p>
    <p><span class="label">Category</span><br><span class="value">{{ str_replace('_', ' ', ucfirst($document_category)) }}</span></p>
    <p><span class="label">Downloaded by</span><br><span class="value">{{ $downloaded_by_name }} ({{ $downloaded_by_email }})</span></p>
    <p><span class="label">Time</span><br><span class="value">{{ $downloaded_at->timezone('Asia/Dubai')->format('d M Y, H:i') }}</span></p>
</div>
@endsection
