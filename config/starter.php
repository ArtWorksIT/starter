<?php

return [
    // Used for markers + manifest metadata
    'package_name' => 'artworksit/starter',
    'version' => '0.1.2',
    'manifest_path' => storage_path('app/starter/manifest.json'),
    'og' => [
        'csv_path' => 'resources/og.csv',
        'input_default' => 'public/assets/og/default.png',
        'output_default' => 'public/og',
        'heading_font' => '',
        'heading_size' => 72,
        'heading_color' => '#111827',
        'heading_x' => 120,
        'heading_y' => 160,
        'heading_max_width' => 900,
        'heading_line_height' => 1.1,
        'subtext_font' => '',
        'subtext_size' => 32,
        'subtext_color' => '#6B7280',
        'subtext_x' => 120,
        'subtext_y' => 320,
        'subtext_max_width' => 900,
        'subtext_line_height' => 1.3,
    ],
];
