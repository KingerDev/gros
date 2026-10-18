{{-- Verejné stránky pre App Store (súkromie, podpora) — bez Inertia a assetov --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Groš</title>
    <style>
        :root { --text: #20212e; --muted: #6b6d7e; --line: #e6e7ee; --bg: #f7f7fb; --card: #fff; --accent: #6c5ce7; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #ecedf3; --muted: #a0a2b3; --line: #2c2e3d; --bg: #14151d; --card: #1c1d28; --accent: #9b8cff; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
        .brand { font-weight: 700; font-size: 18px; color: var(--accent); text-decoration: none; }
        h1 { font-size: 28px; line-height: 1.2; margin: 12px 0 4px; }
        h2 { font-size: 19px; margin: 28px 0 8px; }
        h3 { font-size: 16px; margin: 20px 0 4px; }
        p, ul { margin: 0 0 12px; }
        ul { padding-left: 20px; }
        .muted { color: var(--muted); font-size: 14px; }
        .lang { margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--line); }
        a { color: var(--accent); }
        nav { display: flex; gap: 16px; margin-top: 24px; font-size: 14px; }
    </style>
</head>
<body>
<main>
    <a class="brand" href="{{ url('/') }}">Groš</a>
    <div class="card" style="margin-top: 16px">
        @yield('content')
    </div>
    <nav>
        <a href="{{ url('/privacy') }}">Privacy Policy</a>
        <a href="{{ url('/support') }}">Support</a>
        <a href="#sk">Slovensky</a>
    </nav>
</main>
</body>
</html>
