@extends('emails.layouts.medcon', ['emailTitle' => 'Contact updated', 'preheader' => 'Contact information was updated in Medcon Database.'])

@section('content')
    <p class="eyebrow">Contact activity</p>
    <h1>Contact information updated</h1>
    <p>{{ $user_name }} updated a contact record at {{ $update_time }} (Dubai).</p>
    <table role="presentation" class="comparison">
        <tr>
            <td>
                <div class="comparison-title">Previous</div>
                <p><span class="label">Name</span><br><span class="value">{{ $old_firstname ?: '—' }} {{ $old_lastname ?: '' }}</span></p>
                <p><span class="label">Email</span><br><span class="value">{{ $old_email ?: '—' }}</span></p>
                <p><span class="label">Phone</span><br><span class="value">{{ $old_phone ?: '—' }}</span></p>
                <p><span class="label">Country</span><br><span class="value">{{ $old_country ?: '—' }}</span></p>
                <p><span class="label">Speciality</span><br><span class="value">{{ $old_speciality ?: '—' }}</span></p>
            </td>
            <td>
                <div class="comparison-title">Updated</div>
                <p><span class="label">Name</span><br><span class="value">{{ $new_firstname ?: '—' }} {{ $new_lastname ?: '' }}</span></p>
                <p><span class="label">Email</span><br><span class="value">{{ $new_email ?: '—' }}</span></p>
                <p><span class="label">Phone</span><br><span class="value">{{ $new_phone ?: '—' }}</span></p>
                <p><span class="label">Country</span><br><span class="value">{{ $new_country ?: '—' }}</span></p>
                <p><span class="label">Speciality</span><br><span class="value">{{ $new_speciality ?: '—' }}</span></p>
            </td>
        </tr>
    </table>
    <p class="hint">Performed by {{ $user_email }}</p>
@endsection
