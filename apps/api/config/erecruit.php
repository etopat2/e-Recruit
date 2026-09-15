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
    'hard_copy' => [
        'default_checklist' => [
            ['document_type' => 'national_id', 'label' => 'National ID'],
            ['document_type' => 'application_letter', 'label' => 'Application letter'],
            ['document_type' => 'lc1_letter', 'label' => 'LC1 letter'],
            ['document_type' => 'academic_certificate', 'label' => 'S.4 certificate or result slip'],
            ['document_type' => 'passport_photo', 'label' => 'Passport photo'],
        ],
    ],
    'interview' => [
        'default_invitation_instructions' => [
            'Bring this invitation and your National ID.',
            'Bring the originals of every document submitted with your application.',
            'Report at the stated centre before the reporting time.',
        ],
    ],
    'institution_directory' => [
        'maximum_results' => 7,
        'search_cache_hours' => (int) env('INSTITUTION_SEARCH_CACHE_HOURS', 24),
        'request_timeout_seconds' => (int) env('INSTITUTION_DIRECTORY_TIMEOUT_SECONDS', 30),
        'emis_sync_page_size' => (int) env('EMIS_INSTITUTION_SYNC_PAGE_SIZE', 1000),
        'emis_sync_timeout_seconds' => (int) env('EMIS_INSTITUTION_SYNC_TIMEOUT_SECONDS', 90),
        'emis_search_url' => env('EMIS_INSTITUTION_SEARCH_URL', 'https://emis.go.ug/emis/public-search'),
        'nche_institutions_url' => env('NCHE_INSTITUTIONS_URL', 'https://unche.or.ug/institutions/'),
        'tvet_institutions_url' => env('TVET_INSTITUTIONS_URL', 'https://tvet.go.ug/institutions'),
        'tvet_directory_url' => env('TVET_DIRECTORY_URL', 'https://tvet.go.ug/institution'),
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
