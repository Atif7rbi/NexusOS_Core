<?php

return [
    'demo_mode' => (bool) env('NEXUSOS_DEMO_MODE', false),

    'demo_company_profile' => [
        'company_name_ar' => env(
            'NEXUSOS_DEMO_COMPANY_NAME_AR',
            'شركة أسوار للتطوير العقاري',
        ),
        'company_name_en' => env(
            'NEXUSOS_DEMO_COMPANY_NAME_EN',
            'Aswar Real Estate Development',
        ),
        'short_name_ar' => env(
            'NEXUSOS_DEMO_SHORT_NAME_AR',
            'أسوار',
        ),
        'short_name_en' => env(
            'NEXUSOS_DEMO_SHORT_NAME_EN',
            'Aswar',
        ),
    ],
];
