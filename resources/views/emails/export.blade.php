@extends('emails.layouts.medcon', ['emailTitle' => 'Export completed', 'preheader' => 'Contact data was exported from Medcon Database.'])

@section('content')
    <p class="eyebrow">Data security</p>
    <h1>Contact export completed</h1>
    <p>A user exported contact data from Medcon Database.</p>
    <div class="panel">
        <p><span class="label">Performed by</span><br><span class="value">{{ $user_name }} · {{ $user_email }}</span></p>
        <p><span class="label">Exported records</span><br><span class="value">{{ number_format($export_count) }}</span></p>
        <p><span class="label">Export time</span><br><span class="value">{{ $export_time }} (Dubai)</span></p>
    </div>
    <div class="notice">Exported contact data should be handled according to your organization's privacy and security requirements.</div>
@endsection
