<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · Medcon Database</title>
    <style>
        :root {
            color-scheme: light;
            --brand: #1aabe3;
            --navy: #030372;
            --ink: #13213c;
            --muted: #64748b;
            --border: #dce5ef;
            --surface: #ffffff;
            --background: #f3f7fb;
            --danger: #b42318;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 18px;
            background:
                radial-gradient(circle at 12% 12%, rgba(26, 171, 227, .14), transparent 30%),
                radial-gradient(circle at 88% 90%, rgba(3, 3, 114, .10), transparent 34%),
                var(--background);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .shell { width: 100%; max-width: 520px; }
        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 22px;
            color: var(--navy);
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: linear-gradient(145deg, var(--brand), var(--navy));
            color: white;
            font-size: 20px;
            box-shadow: 0 10px 24px rgba(3, 3, 114, .18);
        }
        .card {
            border: 1px solid rgba(220, 229, 239, .9);
            border-radius: 20px;
            background: rgba(255, 255, 255, .96);
            padding: 36px;
            box-shadow: 0 24px 70px rgba(15, 35, 68, .12);
        }
        h1 { margin: 0 0 10px; color: var(--navy); font-size: 28px; line-height: 1.2; }
        .lead { margin: 0 0 28px; color: var(--muted); line-height: 1.65; }
        .field { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 700; }
        input {
            width: 100%;
            height: 48px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0 14px;
            background: white;
            color: var(--ink);
            font: inherit;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(26, 171, 227, .13); }
        input[readonly] { background: #f8fafc; color: var(--muted); }
        .hint { margin: 8px 0 0; color: var(--muted); font-size: 13px; line-height: 1.5; }
        .requirements { margin: 10px 0 0; padding-left: 20px; color: var(--muted); font-size: 13px; line-height: 1.7; }
        .requirements li::marker { color: #94a3b8; }
        .requirements li.valid { color: #16763a; }
        .requirements li.valid::marker { color: #16763a; }
        .text-danger { color: var(--danger); }
        .error {
            margin: 0 0 20px;
            padding: 12px 14px;
            border: 1px solid #f7c5c0;
            border-radius: 10px;
            background: #fff4f2;
            color: var(--danger);
            font-size: 14px;
            line-height: 1.5;
        }
        button, .button {
            width: 100%;
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 10px;
            padding: 12px 18px;
            background: linear-gradient(135deg, var(--brand), #168ac6);
            color: white;
            font: inherit;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 10px 22px rgba(26, 171, 227, .22);
        }
        button:hover, .button:hover { filter: brightness(.96); }
        .security { margin: 22px 0 0; color: var(--muted); font-size: 12px; line-height: 1.6; text-align: center; }
        @media (max-width: 560px) {
            body { padding: 22px 14px; }
            .card { padding: 26px 20px; border-radius: 16px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="brand"><span class="brand-mark">M</span> Medcon Database</div>
        <section class="card">
            @yield('content')
        </section>
        <p class="security">Secure account setup · This page is provided directly by Medcon Database.</p>
    </main>
    @yield('scripts')
</body>
</html>
