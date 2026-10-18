@extends('legal.layout')

@section('title', 'Support')

@section('content')
    <h1>Groš Support</h1>
    <p>Need help with Groš? Write to <a href="mailto:it.martinbalaz@gmail.com">it.martinbalaz@gmail.com</a> — we usually reply within a few days.</p>

    <h2>FAQ</h2>

    <h3>How do I delete my account?</h3>
    <p>In the app open <strong>Settings → Delete account</strong>. If you have a password, you will be asked to confirm it. Your account and all your data are deleted permanently. You can also ask by email.</p>

    <h3>I forgot my password</h3>
    <p>On the sign-in screen tap <strong>Forgot password?</strong>, enter your email and open the link we send you. If you signed up with Apple, just use <strong>Sign in with Apple</strong> — no password needed.</p>

    <h3>Is my data safe?</h3>
    <p>Groš has no ads, no tracking and no third-party analytics, and it never connects to your bank. See the <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>

    <h3>Contact</h3>
    <p><a href="mailto:it.martinbalaz@gmail.com">it.martinbalaz@gmail.com</a></p>

    <section class="lang" id="sk" lang="sk">
        <h1>Podpora Groša</h1>
        <p>Potrebuješ pomôcť? Napíš na <a href="mailto:it.martinbalaz@gmail.com">it.martinbalaz@gmail.com</a> — zvyčajne odpovieme do pár dní.</p>

        <h2>Časté otázky</h2>

        <h3>Ako zmažem účet?</h3>
        <p>V appke otvor <strong>Nastavenia → Zmazať účet</strong>. Ak máš heslo, appka si ho vypýta. Účet aj všetky údaje sa zmažú natrvalo. Môžeš o to požiadať aj e-mailom.</p>

        <h3>Zabudol som heslo</h3>
        <p>Na prihlasovacej obrazovke ťukni na <strong>Zabudnuté heslo?</strong>, zadaj e-mail a otvor odkaz, ktorý ti pošleme. Ak si sa registroval cez Apple, prihlás sa cez <strong>Prihlásiť sa cez Apple</strong> — heslo netreba.</p>

        <h3>Sú moje údaje v bezpečí?</h3>
        <p>Groš nemá reklamy, sledovanie ani analytiku tretích strán a nikdy sa nepripája k banke. Viac v <a href="{{ url('/privacy') }}">zásadách ochrany súkromia</a>.</p>

        <h3>Kontakt</h3>
        <p><a href="mailto:it.martinbalaz@gmail.com">it.martinbalaz@gmail.com</a></p>
    </section>
@endsection
