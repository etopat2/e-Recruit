<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: Tahoma; src: url('{{ $tahomaRegularDataUri }}') format('truetype'); font-weight: 400; }
        @font-face { font-family: Tahoma; src: url('{{ $tahomaBoldDataUri }}') format('truetype'); font-weight: 700; }
        @page { margin: 18mm 12mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: Tahoma, sans-serif; font-size: 8.4pt; line-height: 1.25; }
        .cover, .list-header { display: block; width: 100%; }
        .official-header { width: 100%; min-height: 29mm; border-collapse: collapse; table-layout: fixed; }
        .official-header td { padding: 0; border: 0; background: #fff !important; vertical-align: middle; }
        .official-header .mark-cell { width: 27mm; text-align: center; }
        .official-header .service-name { font-size: 12.5pt; font-weight: 700; letter-spacing: -.2pt; text-align: center; white-space: nowrap; }
        .official-header .ups-mark { width: 17mm; max-height: 25mm; }
        .official-header .national-emblem { width: 24mm; max-height: 25mm; }
        .document-title { margin: 2mm 0 0; font-size: 12pt; font-weight: 700; text-transform: uppercase; }
        .post-title { margin: 1mm 0; font-size: 10pt; font-weight: 700; text-transform: uppercase; }
        .cover { page-break-after: always; font-size: 10.5pt; line-height: 1.42; }
        .cover .official-header { margin-bottom: 8mm; }
        .cover-copy { display: block; width: 82%; margin: 0 auto; }
        .cover-copy p { text-align: justify; }
        .cover h2 { margin: 5mm 0 2mm; font-size: 11pt; text-transform: uppercase; }
        .cover table { font-size: 8.2pt; }
        .authority { margin-top: 13mm; font-weight: 700; text-align: center; }
        .authority .signature-space { width: 62mm; height: 12mm; margin: 0 auto 2mm; border-bottom: 1px solid #000; }
        .system-note { margin-top: 3mm; color: #555; font-size: 7.5pt; font-weight: 400; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 1.5mm 1.3mm; border: .35pt solid #6e6e6e; vertical-align: top; }
        th { background: #d9d9d9; font-size: 7.7pt; text-align: left; text-transform: uppercase; }
        tbody tr:nth-child(even) td { background: #ececec; }
        .district-heading td { padding: 1.5mm; border-color: #a92028; background: #c91e2c !important; color: #fff; font-size: 9pt; font-weight: 700; text-transform: uppercase; }
        .number { width: 8mm; text-align: center; }
        .sex, .age { width: 12mm; text-align: center; }
        .nin { white-space: nowrap; }
        .list-header { margin-bottom: 3mm; }
        .list-header .official-header { min-height: 27mm; }
        .list-header .document-title, .list-header .post-title { text-align: center; }
        .record-count { margin: 1mm 0 2mm; color: #444; text-align: right; font-size: 7.5pt; }
        .empty { padding: 12mm; text-align: center; }
        .footer { position: fixed; right: 0; bottom: -10mm; left: 0; border-top: .4pt solid #999; color: #555; font-size: 7pt; }
        .footer .left { position: absolute; top: 0; left: 0; }
        .footer .right { position: absolute; top: 0; right: 0; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
@php
    $officialHeader = function (?string $heading = null) use ($logoDataUri, $nationalEmblemDataUri, $post, $campaign) {
        $title = $heading === null ? '' : '<p class="document-title">'.e($heading).'</p><p class="post-title">'.e($post->name).' ('.e($campaign->year).')</p>';
        return '<table class="official-header" role="presentation"><tr><td class="mark-cell"><img class="ups-mark" src="'.e($logoDataUri).'" alt=""></td><td class="service-name">UGANDA PRISONS SERVICE</td><td class="mark-cell"><img class="national-emblem" src="'.e($nationalEmblemDataUri).'" alt=""></td></tr></table>'.$title;
    };
@endphp

@if($type === 'medical_examination_shortlist')
    <div class="cover">
        {!! $officialHeader() !!}
        <div class="cover-copy">
        <p>The candidates listed in this document were selected through the certified recruitment process for the post of <strong>{{ $post->name }}</strong>. They are required to report for medical examination at the approved Uganda Prisons Service facility and time communicated through their secure invitation.</p>
        <p>Candidates must carry their National Identification Card and the original documents specified in the recruitment advert. Inclusion in this medical-examination list is provisional and is not a final appointment.</p>
        <h2>Approved medical examination facilities</h2>
        <table><thead><tr><th class="number">No.</th><th>Facility</th><th>Location</th></tr></thead><tbody>@foreach($facilities as $facility)<tr><td class="number">{{ $loop->iteration }}</td><td>{{ $facility->name }}</td><td>{{ $facility->location }}</td></tr>@endforeach</tbody></table>
        @if($schedules->isNotEmpty())<p><strong>Scheduled sessions:</strong> {{ $schedules->map(fn ($schedule) => $schedule->facility.' — '.$schedule->scheduled_date.' at '.substr($schedule->reporting_time, 0, 5))->implode('; ') }}.</p>@endif
        <div class="authority"><div class="signature-space"></div>FOR COMMISSIONER GENERAL OF PRISONS<p class="system-note">System-generated authorization copy. A handwritten signature is never copied from an earlier recruitment notice.</p></div>
        </div>
    </div>
@elseif($type === 'final_successful_candidates')
    <div class="cover">
        {!! $officialHeader() !!}
        <div class="cover-copy">
        <p>Following completion of the approved recruitment stages, including interviews and medical examination, Uganda Prisons Service announces the successful candidates listed in this document for the post of <strong>{{ $post->name }}</strong>.</p>
        @if($training)<p>Successful candidates shall report to <strong>{{ $training->location }}</strong> on <strong>{{ $training->reporting_date }}</strong> at <strong>{{ substr($training->reporting_time, 0, 5) }}</strong>, subject to the individual instructions in their secure training invitation.</p>@else<p>Reporting dates, time, venue, and items to carry are stated in each successful candidate’s secure training invitation. Candidates must rely on that invitation and official Uganda Prisons Service channels.</p>@endif
        <h2>Important notice</h2>
        @php
            $reportingInstructions = $training?->instructions;
            $reportingInstructions = is_string($reportingInstructions) ? json_decode($reportingInstructions, true) : (array) $reportingInstructions;
            $reportingInstructions = array_values(array_filter($reportingInstructions));
        @endphp
        <ol>
            @forelse($reportingInstructions as $instruction)<li>{{ $instruction }}</li>
            @empty
                <li>Carry the original National Identification Card and all recruitment documents.</li>
                <li>Present the secure training invitation for verification.</li>
                <li>Do not pay any person for appointment, placement, or reporting.</li>
                <li>Any falsified record remains subject to cancellation and lawful action.</li>
            @endforelse
        </ol>
        <div class="authority"><div class="signature-space"></div>FOR COMMISSIONER GENERAL OF PRISONS<p class="system-note">System-generated authorization copy. A handwritten signature is never copied from an earlier recruitment notice.</p></div>
        </div>
    </div>
@endif

<div class="list-header">
    {!! $officialHeader($title) !!}
    <p class="record-count">{{ number_format($rows->count()) }} candidate(s) · generated {{ $generatedAt->format('d M Y H:i T') }}</p>
</div>

@if($type === 'interview_shortlist')
    <table>
        <thead><tr><th class="number">No.</th><th>First Name</th><th>Middle Name</th><th>Last Name</th><th>NIN</th><th class="sex">Sex</th><th class="age">Age</th><th>Recruitment Centre</th></tr></thead>
        <tbody>
        @forelse($rows->groupBy('district') as $district => $districtRows)
            <tr class="district-heading"><td colspan="8">{{ $district }}</td></tr>
            @foreach($districtRows as $row)<tr><td class="number">{{ $loop->iteration }}</td><td>{{ $row['first_name'] }}</td><td>{{ $row['middle_names'] }}</td><td>{{ $row['last_name'] }}</td><td class="nin">{{ $row['nin'] }}</td><td class="sex">{{ $row['sex'] }}</td><td class="age">{{ $row['age'] }}</td><td>{{ $row['recruitment_centre'] }}</td></tr>@endforeach
        @empty<tr><td class="empty" colspan="8">No approved interview assignments were available when this document was generated.</td></tr>@endforelse
        </tbody>
    </table>
@elseif($type === 'medical_examination_shortlist')
    <table><thead><tr><th class="number">S/No</th><th>Name</th><th>NIN Ending</th><th>District of Origin</th></tr></thead><tbody>@forelse($rows as $row)<tr><td class="number">{{ $row['number'] }}</td><td>{{ $row['full_name'] }}</td><td class="nin">{{ $row['nin'] }}</td><td>{{ $row['district'] }}</td></tr>@empty<tr><td class="empty" colspan="4">No candidates were present in the latest certified provisional selection.</td></tr>@endforelse</tbody></table>
@else
    <table><thead><tr><th>Name</th><th class="sex">Gender</th><th>NIN Ending</th><th>District</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row['full_name'] }}</td><td class="sex">{{ $row['sex'] }}</td><td class="nin">{{ $row['nin'] }}</td><td>{{ $row['district'] }}</td></tr>@empty<tr><td class="empty" colspan="4">No council-approved successful candidates were available when this document was generated.</td></tr>@endforelse</tbody></table>
@endif

<div class="footer"><span class="left">UPS {{ $campaign->year }} · {{ $post->name }} · {{ $documentId }}</span><span class="right">Page <span class="page-number"></span></span></div>
</body>
</html>
