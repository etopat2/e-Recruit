<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#241b1d}h1{color:#6b1229}.header{text-align:center;border-bottom:4px solid #74163d}.logo{height:90px}.box{border:1px solid #d9c8a0;padding:18px;margin:18px 0}li{margin:7px 0}.qr{text-align:center}.qr img{height:130px;width:130px}</style></head>
<body>
<div class="header">@if($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="Uganda Prisons Service crest">@endif<h1>Uganda Prisons Service — Training Invitation</h1></div>
<div class="box">
    <p><strong>Application reference:</strong> {{ $selection->reference }}</p>
    <p><strong>Reporting date:</strong> {{ $invite->reporting_date }} at {{ $invite->reporting_time }}</p>
    <p><strong>Location:</strong> {{ $invite->location }}</p>
</div>
<h2>Instructions</h2>
<ul>@foreach($invite->instructions as $instruction)<li>{{ $instruction }}</li>@endforeach</ul>
<div class="qr"><img src="{{ $qrDataUri }}" alt="Invitation verification QR code"><p>{{ $selection->reference }}</p></div>
<p>This document must be presented with valid identification. Its authenticity is verifiable through the e-Recruit portal.</p>
</body>
</html>
