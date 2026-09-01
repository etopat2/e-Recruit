<?php

return [
    'seed_demo_users' => (bool) env('SEED_DEMO_USERS', false),
    'reference_pattern' => env('APPLICATION_REFERENCE_PATTERN', 'UPS/{year}/{post}/{sequence}'),
    'reference_digits' => (int) env('APPLICATION_REFERENCE_DIGITS', 6),
    'document_worker' => [
        'url' => env('DOCUMENT_WORKER_URL', 'http://document-worker:8001'),
        'token' => env('DOCUMENT_WORKER_TOKEN'),
        'timeout_seconds' => (int) env('DOCUMENT_WORKER_TIMEOUT_SECONDS', 60),
    ],
    'uploads' => [
        'disk' => env('DOCUMENT_DISK', 's3'),
        'maximum_bytes' => (int) env('DOCUMENT_MAX_BYTES', 15728640),
        'allowed_mime_types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ],
        'malware_scanner' => env('MALWARE_SCANNER', 'development'),
        'clamav_host' => env('CLAMAV_HOST', 'clamav'),
        'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    ],
    'offline' => [
        'default_expiry_hours' => (int) env('OFFLINE_PACK_EXPIRY_HOURS', 24),
        'maximum_records' => (int) env('OFFLINE_PACK_MAXIMUM_RECORDS', 1500),
        'protected_fields' => [
            'score',
            'status',
            'verified_value',
            'eligibility_decision',
            'panel_closure',
            'medical_outcome',
        ],
    ],
    'security' => [
        'privileged_roles' => [
            'hq_recruitment_administrator',
            'prisons_council_secretariat',
            'system_administrator',
            'auditor',
            'medical_officer',
            'panel_head',
            'regional_recruitment_officer',
        ],
        'medical_roles' => ['medical_officer'],
        'national_roles' => [
            'hq_recruitment_administrator',
            'prisons_council_secretariat',
            'executive_viewer',
            'auditor',
        ],
    ],
];
