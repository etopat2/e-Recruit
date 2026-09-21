<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>UPS e-Recruit MFA recovery codes</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;color:#241a1d;line-height:1.5">
    <main style="max-width:560px;margin:auto;padding:24px">
        <h1 style="color:#5c1d2b">Your MFA recovery codes</h1>
        <p>MFA enrolment on your UPS e-Recruit account is complete. These are the same recovery codes shown in the app.</p>
        <ul style="font-family:monospace;font-size:18px">
            @foreach ($codes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>
        <p>Each code can be used once if you cannot use your enrolled MFA method. Keep them in a secure place separate from your password. Never share them.</p>
        <p>These are recovery codes, not the six-digit email login code. A new authorised MFA enrolment replaces these codes.</p>
        <p>If you did not enrol MFA, contact the technical team immediately.</p>
    </main>
</body>
</html>
