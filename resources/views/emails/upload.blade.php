@extends('emails.layouts.medcon', ['emailTitle' => 'Import completed', 'preheader' => 'A CSV import completed in Medcon Database.'])

@section('content')
    <p class="eyebrow">Data operation</p>
    <h1>CSV import completed</h1>
    <p>A contact import was completed successfully.</p>
    <div class="panel">
        <p><span class="label">Performed by</span><br><span class="value">{{ $user_name }} · {{ $user_email }}</span></p>
        <p><span class="label">Completed</span><br><span class="value">{{ $entry_time }} (Dubai)</span></p>
        <p><span class="label">Result</span><br><span class="value">{{ $data_summary }}</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}" class="button">Open contacts</a></p>
@endsection
