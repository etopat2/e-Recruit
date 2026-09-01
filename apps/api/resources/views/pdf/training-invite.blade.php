<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#241b1d}h1{color:#6b1229}.box{border:1px solid #d9c8a0;padding:18px;margin:18px 0}li{margin:7px 0}</style></head>
<body>
<h1>Uganda Prisons Service — Training Invitation</h1>
<div class="box">
    <p><strong>Application reference:</strong> {{ $selection->reference }}</p>
    <p><strong>Reporting date:</strong> {{ $invite->reporting_date }} at {{ $invite->reporting_time }}</p>
    <p><strong>Location:</strong> {{ $invite->location }}</p>
</div>
<h2>Instructions</h2>
<ul>@foreach($invite->instructions as $instruction)<li>{{ $instruction }}</li>@endforeach</ul>
<p>This document must be presented with valid identification. Its authenticity is verifiable through the e-Recruit portal.</p>
</body>
</html>
