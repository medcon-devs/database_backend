@extends('emails.layouts.medcon', ['emailTitle' => 'Contact restored', 'preheader' => 'A deleted contact was restored in Medcon Database.'])

@section('content')
    <p class="eyebrow">Contact activity</p>
    <h1>Contact restored</h1>
    <p>A previously deleted contact is active again.</p>
    <div class="panel">
        <p><span class="label">Performed by</span><br><span class="value">{{ $user_name }} · {{ $user_email }}</span></p>
        <p><span class="label">Restore time</span><br><span class="value">{{ $restore_time }} (Dubai)</span></p>
        <p><span class="label">Contact</span><br><span class="value">{{ $data_summary }}</span></p>
    </div>
    <p style="text-align: center;"><a href="{{ rtrim(config('mail.application_url'), '/') }}" class="button">Open contacts</a></p>
@endsection
