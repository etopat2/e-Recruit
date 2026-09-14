<?php

$pleResults = [
    ['value' => 'Division 1', 'label' => 'Division 1'],
    ['value' => 'Division 2', 'label' => 'Division 2'],
    ['value' => 'Division 3', 'label' => 'Division 3'],
    ['value' => 'Division 4', 'label' => 'Division 4'],
    ['value' => 'Ungraded (Division U)', 'label' => 'Ungraded (Division U)'],
    ['value' => 'Absent / incomplete (Division X)', 'label' => 'Absent / incomplete (Division X)'],
];

$uceResults = [
    ['value' => 'Result 1 (Certificate awarded)', 'label' => 'Result 1 — certificate awarded (current curriculum)'],
    ['value' => 'Result 2 (Certificate conditions not fulfilled)', 'label' => 'Result 2 — certificate conditions not fulfilled (current)'],
    ['value' => 'Result 3 (Minimum achievement not met)', 'label' => 'Result 3 — minimum achievement not met (current)'],
    ['value' => 'Result 4 (Partially or fully absent)', 'label' => 'Result 4 — partially or fully absent (current)'],
    ['value' => 'Division 1', 'label' => 'Division 1 (legacy curriculum)'],
    ['value' => 'Division 2', 'label' => 'Division 2 (legacy curriculum)'],
    ['value' => 'Division 3', 'label' => 'Division 3 (legacy curriculum)'],
    ['value' => 'Division 4', 'label' => 'Division 4 (legacy curriculum)'],
    ['value' => 'Result 7 (Certificate not awarded)', 'label' => 'Result 7 — certificate not awarded (legacy entry rules)'],
    ['value' => 'Ungraded (Division U)', 'label' => 'Ungraded / Division U (legacy curriculum)'],
    ['value' => 'Absent / incomplete (Division X)', 'label' => 'Absent / incomplete / Division X (legacy)'],
];

$uaceResults = [
    ['value' => '3 Principal passes (3P)', 'label' => '3 Principal-level passes (3P)'],
    ['value' => '2 Principal passes (2P)', 'label' => '2 Principal-level passes (2P)'],
    ['value' => '1 Principal pass (1P)', 'label' => '1 Principal-level pass (1P)'],
    ['value' => '1 Subsidiary pass (1S)', 'label' => '1 Subsidiary-level pass (1S)'],
    ['value' => 'Fail (F)', 'label' => 'Fail (F)'],
];

$ncheClassifications = [
    ['value' => 'First Class (CGPA 4.4–5.0)', 'label' => 'First Class — CGPA 4.4–5.0'],
    ['value' => 'Second Class Upper Division (CGPA 4.0–4.3)', 'label' => 'Second Class, Upper Division — CGPA 4.0–4.3'],
    ['value' => 'Second Class Lower Division (CGPA 3.0–3.9)', 'label' => 'Second Class, Lower Division — CGPA 3.0–3.9'],
    ['value' => 'Pass (CGPA 2.0–2.9)', 'label' => 'Pass — CGPA 2.0–2.9'],
    ['value' => 'Fail / not awarded (CGPA 0–1.9)', 'label' => 'Fail / not awarded — CGPA 0–1.9'],
    ['value' => 'Awarded (not classified)', 'label' => 'Awarded — qualification not classified'],
];

$postgraduateClassifications = [
    ['value' => 'Distinction', 'label' => 'Distinction'],
    ['value' => 'Merit / Credit', 'label' => 'Merit / Credit'],
    ['value' => 'Pass', 'label' => 'Pass'],
    ['value' => 'Awarded (not classified)', 'label' => 'Awarded — qualification not classified'],
    ['value' => 'Fail / not awarded', 'label' => 'Fail / not awarded'],
];

$doctorateResults = [
    ['value' => 'Degree awarded (not classified)', 'label' => 'Degree awarded — not classified'],
    ['value' => 'Fail / not awarded', 'label' => 'Not awarded'],
];

$tvetClassifications = [
    ['value' => 'Class I (Distinction)', 'label' => 'Class I — Distinction'],
    ['value' => 'Class II (Credit)', 'label' => 'Class II — Credit'],
    ['value' => 'Class III (Pass)', 'label' => 'Class III — Pass'],
    ['value' => 'Conceded Pass (CP)', 'label' => 'Conceded Pass (CP)'],
    ['value' => 'Competence Class Certificate (partial modules)', 'label' => 'Competence Class Certificate — partial modules'],
    ['value' => 'Awarded (not classified)', 'label' => 'Awarded — qualification not classified'],
    ['value' => 'Fail / not awarded', 'label' => 'Fail / not awarded'],
];

$uvqfResults = [
    ['value' => 'Full qualification awarded', 'label' => 'Full occupational qualification awarded'],
    ['value' => 'Partial qualification (modular transcript)', 'label' => 'Partial qualification — modular transcript'],
    ['value' => 'Not yet competent / not awarded', 'label' => 'Not yet competent / not awarded'],
];

$workersPasResults = [
    ['value' => 'Full occupation certified', 'label' => 'Full occupation certified'],
    ['value' => 'Partial modules certified', 'label' => 'Partial occupational modules certified'],
    ['value' => 'Not yet certified', 'label' => 'Not yet certified'],
];

$legacyTradeResults = [
    ['value' => 'Awarded / passed', 'label' => 'Awarded / passed'],
    ['value' => 'Fail / not awarded', 'label' => 'Fail / not awarded'],
];

$level = static fn (
    string $value,
    string $label,
    string $guidance,
    array $results,
    ?string $directorySource,
): array => [
    'value' => $value,
    'label' => $label,
    'guidance' => $guidance,
    'results' => $results,
    'directory_searchable' => $directorySource !== null,
    'directory_source' => $directorySource,
];

$groups = [
    [
        'label' => 'School education',
        'options' => [
            $level('PLE', 'Primary Leaving Examination (PLE) — National Level 1', 'Choose the overall PLE division printed on the result slip or certificate.', $pleResults, 'emis'),
            $level('UCE', 'Uganda Certificate of Education (UCE / O-Level) — National Level 2', 'For the competency-based curriculum, choose overall Result 1–4. A–E achievement levels are subject grades, not an overall class. Legacy UCE divisions remain available.', $uceResults, 'emis'),
            $level('UACE', 'Uganda Advanced Certificate of Education (UACE / A-Level) — National Level 3', 'Choose the overall UNEB pass level: principal passes, subsidiary pass, or fail.', $uaceResults, 'emis'),
        ],
    ],
    [
        'label' => 'University and other higher education',
        'options' => [
            $level('Higher Education Certificate', 'Higher Education Certificate / Foundation — UHEQF Level 4', 'Choose the final classification exactly as printed by the awarding institution.', $ncheClassifications, 'nche'),
            $level('Diploma', 'Ordinary Diploma — UHEQF Level 5', 'Choose the final classification exactly as printed by the awarding institution.', $ncheClassifications, 'nche'),
            $level('Higher Diploma', 'Advanced / Higher Diploma — UHEQF Level 6', 'Choose the final classification exactly as printed by the awarding institution.', $ncheClassifications, 'nche'),
            $level("Bachelor's Degree", 'Bachelor’s Degree — UHEQF Level 7', 'Use the final award classification on the transcript or certificate.', $ncheClassifications, 'nche'),
            $level('Postgraduate Certificate', 'Postgraduate Certificate — UHEQF Level 8', 'Postgraduate wording varies by institution; choose the classification printed on the award.', $postgraduateClassifications, 'nche'),
            $level('Postgraduate Diploma', 'Postgraduate Diploma — UHEQF Level 8', 'Postgraduate wording varies by institution; choose the classification printed on the award.', $postgraduateClassifications, 'nche'),
            $level("Master's Degree", 'Master’s Degree — UHEQF Level 8', 'Master’s awards may be classified or unclassified; match the wording on the official award.', $postgraduateClassifications, 'nche'),
            $level('Doctorate (PhD)', 'Doctoral Degree / PhD — UHEQF Level 9', 'Doctoral degrees are normally awarded without a class.', $doctorateResults, 'nche'),
        ],
    ],
    [
        'label' => 'Technical, vocational, tertiary, and institute awards',
        'options' => [
            $level('Uganda Community Polytechnic Certificate', 'Uganda Community Polytechnic Certificate', 'Choose the final class or award status printed on the certificate or transcript.', $tvetClassifications, 'tvet'),
            $level('National Certificate (TVET)', 'National Certificate (TVET)', 'Choose the final class or award status printed on the certificate or transcript.', $tvetClassifications, 'tvet'),
            $level('National Craftsperson Certificate (TVET)', 'National Craftsperson Certificate (TVET)', 'Choose the final class or competence result printed on the award.', $tvetClassifications, 'tvet'),
            $level('National Diploma (TVET)', 'National / Technician Diploma (TVET)', 'Choose Class I, II, or III when shown, or the exact award status on the transcript.', $tvetClassifications, 'tvet'),
            $level('Higher National Diploma (TVET)', 'Higher National Diploma (TVET)', 'Choose the final class or award status printed on the certificate or transcript.', $tvetClassifications, 'tvet'),
            $level("Bachelor's Degree (TVET)", 'Bachelor’s Degree (TVET)', 'Use the final award classification on the transcript or certificate.', $ncheClassifications, 'tvet'),
            $level('Informal Skills Certificate (TVET)', 'Informal Skills Certificate (TVET)', 'Choose the competence or certification outcome printed on the award.', $uvqfResults, 'tvet'),
            $level('UVQF Basic / Modular Award', 'UVQF Basic / Modular Award', 'UVQF modular learning may be recorded as a partial qualification on a transcript.', $uvqfResults, 'tvet'),
            $level('UVQF Level 1 Certificate', 'UVQF Level 1 Certificate — supervised worker', 'Choose whether the full occupational qualification or only modular units were awarded.', $uvqfResults, 'tvet'),
            $level('UVQF Level 2 Certificate', 'UVQF Level 2 Certificate — moderate supervision', 'Choose whether the full occupational qualification or only modular units were awarded.', $uvqfResults, 'tvet'),
            $level('UVQF Level 3 Certificate', 'UVQF Level 3 Certificate — supervisory competence', 'Choose whether the full occupational qualification or only modular units were awarded.', $uvqfResults, 'tvet'),
            $level('UVQF Level 4 Diploma', 'UVQF Level 4 Diploma — technician competence', 'Choose whether the full occupational qualification or only modular units were awarded.', $uvqfResults, 'tvet'),
            $level("Worker'sPAS", 'Worker’sPAS — formally recognised workplace skills', 'Choose whether the full occupation or only specific modules were certified.', $workersPasResults, 'tvet'),
            $level('Craft Certificate (legacy TVET)', 'Craft Certificate (legacy TVET award)', 'Choose the final class printed on the historical award.', $tvetClassifications, 'tvet'),
            $level('Advanced Craft Certificate (legacy TVET)', 'Advanced Craft Certificate (legacy TVET award)', 'Choose the final class printed on the historical award.', $tvetClassifications, null),
            $level('Trade Test Grade II (legacy)', 'Trade Test Grade II — legacy award (replaced by UVQF Level 1)', 'Use this only when it is the title printed on a historical certificate.', $legacyTradeResults, null),
            $level('Trade Test Grade I (legacy)', 'Trade Test Grade I — legacy award (replaced by UVQF Level 2)', 'Use this only when it is the title printed on a historical certificate.', $legacyTradeResults, null),
            $level('Trade Test Master Craft (legacy)', 'Trade Test Master Craft — legacy award (replaced by UVQF Level 3)', 'Use this only when it is the title printed on a historical certificate.', $legacyTradeResults, null),
            $level('Certificate in Vocational Training Instruction (CVTI)', 'Certificate in Vocational Training Instruction (CVTI)', 'Choose the class or award status printed on the certificate.', $tvetClassifications, null),
            $level('Diploma in Vocational Training Instruction (DVTI)', 'Diploma in Vocational Training Instruction (DVTI)', 'Choose the class or award status printed on the diploma.', $tvetClassifications, null),
            $level('Diploma in Training Institution Management (DTIM)', 'Diploma in Training Institution Management (DTIM)', 'Choose the class or award status printed on the diploma.', $tvetClassifications, null),
        ],
    ],
];

$levels = array_merge(...array_column($groups, 'options'));
$directoryLevels = static fn (string $source): array => array_values(array_map(
    static fn (array $item): string => $item['value'],
    array_filter($levels, static fn (array $item): bool => $item['directory_source'] === $source),
));

return [
    'groups' => $groups,
    'levels' => $levels,
    'directory_searchable_levels' => array_values(array_map(
        static fn (array $item): string => $item['value'],
        array_filter($levels, static fn (array $item): bool => $item['directory_searchable']),
    )),
    'directory_levels' => [
        'nche' => $directoryLevels('nche'),
        'tvet' => $directoryLevels('tvet'),
    ],
];
