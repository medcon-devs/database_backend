@extends('auth.layout')

@section('title', $mode === 'reset' ? 'Reset your password' : 'Set your password')

@section('content')
    <h1>{{ $mode === 'reset' ? 'Reset your password' : "Welcome, {$user->name}" }}</h1>
    <p class="lead">
        {{ $mode === 'reset'
            ? 'Choose a new password for your Medcon Database account.'
            : 'Create your password to finish activating your Medcon Database account.' }}
    </p>

    @if ($errors->any())
        <div class="error" role="alert">
            <strong>We could not save your password.</strong><br>
            {{ $errors->first() }}
        </div>
    @endif

    <form id="password-form" method="POST" action="{{ route('password.setup.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="mode" value="{{ $mode }}">

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" readonly autocomplete="username">
        </div>

        <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" required minlength="8" autofocus autocomplete="new-password">
            <ul class="requirements" aria-live="polite">
                <li id="rule-length">At least 8 characters</li>
                <li id="rule-uppercase">One uppercase letter</li>
                <li id="rule-lowercase">One lowercase letter</li>
                <li id="rule-number">One number</li>
            </ul>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            <p id="confirmation-message" class="hint" aria-live="polite"></p>
        </div>

        <button type="submit">{{ $mode === 'reset' ? 'Reset password and continue' : 'Set password and continue' }}</button>
    </form>
@endsection

@section('scripts')
    <script>
        (() => {
            const form = document.getElementById('password-form');
            const password = document.getElementById('password');
            const confirmation = document.getElementById('password_confirmation');
            const confirmationMessage = document.getElementById('confirmation-message');
            const rules = {
                length: { element: document.getElementById('rule-length'), test: value => value.length >= 8 },
                uppercase: { element: document.getElementById('rule-uppercase'), test: value => /[A-Z]/.test(value) },
                lowercase: { element: document.getElementById('rule-lowercase'), test: value => /[a-z]/.test(value) },
                number: { element: document.getElementById('rule-number'), test: value => /\d/.test(value) },
            };

            const validate = () => {
                const value = password.value;
                const missingRules = Object.values(rules).filter(rule => !rule.test(value));

                Object.values(rules).forEach(rule => {
                    rule.element.classList.toggle('valid', rule.test(value));
                });

                password.setCustomValidity(
                    missingRules.length ? 'Use at least 8 characters with uppercase, lowercase, and a number.' : ''
                );

                const confirmationMatches = confirmation.value === value;
                confirmation.setCustomValidity(confirmationMatches ? '' : 'The password confirmation does not match.');
                confirmationMessage.textContent = confirmation.value && !confirmationMatches
                    ? 'Passwords do not match.'
                    : '';
                confirmationMessage.classList.toggle('text-danger', Boolean(confirmation.value && !confirmationMatches));

                return missingRules.length === 0 && confirmationMatches && confirmation.value !== '';
            };

            password.addEventListener('input', validate);
            confirmation.addEventListener('input', validate);
            form.addEventListener('submit', event => {
                if (!validate()) {
                    event.preventDefault();
                    form.reportValidity();
                }
            });
        })();
    </script>
@endsection
