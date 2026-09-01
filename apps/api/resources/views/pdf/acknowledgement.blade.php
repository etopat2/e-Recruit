<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Application acknowledgement</title>
    <style>
        body { color: #242226; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; }
        .header { border-bottom: 4px solid #74163d; padding-bottom: 14px; text-align: center; }
        .logo { height: 112px; }
        h1 { color: #74163d; font-size: 22px; margin: 10px 0 0; }
        .reference { background: #f8f2e6; border: 2px solid #c79a32; font-size: 22px; font-weight: bold; margin: 24px 0; padding: 16px; text-align: center; }
        .grid { width: 100%; }
        .grid td { border-bottom: 1px solid #ddd; padding: 7px; }
        .label { color: #666; width: 34%; }
        .qr { margin-top: 20px; text-align: center; }
        .qr img { height: 150px; width: 150px; }
        .notice { background: #fff8df; border-left: 5px solid #c79a32; margin-top: 22px; padding: 12px; }
        .footer { color: #666; font-size: 10px; margin-top: 25px; text-align: center; }
    </style>
</head>
<body>
<div class="header">
    @if ($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="Uganda Prisons Service crest">@endif
    <h1>Uganda Prisons Service e-Recruit</h1>
    <div>Online application acknowledgement</div>
</div>
<div class="reference">{{ $reference }}</div>
<table class="grid" cellspacing="0">
    <tr><td class="label">Applicant</td><td>{{ $application->applicant->first_name }} {{ $application->applicant->middle_names }} {{ $application->applicant->last_name }}</td></tr>
    <tr><td class="label">Recruitment campaign</td><td>{{ $application->campaign->name }}</td></tr>
    <tr><td class="label">Post/category</td><td>{{ $application->post->name }}</td></tr>
    <tr><td class="label">Submitted</td><td>{{ $application->submitted_at?->timezone('Africa/Kampala')->format('d M Y H:i') }}</td></tr>
</table>
@if ($application->post->hard_copy_required)
<div class="notice"><strong>Hard-copy action required:</strong> Write the application reference shown above clearly on top of your physical application letter and submit the configured hard-copy documents to an approved UPS receiving point before the campaign deadline. Online submission does not replace this step.</div>
@endif
<div class="qr"><img src="{{ $qrDataUri }}" alt="Application verification QR code"><br>Scan to verify this acknowledgement reference.</div>
<div class="footer">System generated. It does not contain an official signature and does not by itself guarantee eligibility or selection.</div>
</body>
</html>
