<?php

use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\TabularExportWriter;

return [
    'models' => [
        'operation' => ExportImportOperation::class,
    ],
    'tables' => [
        'operations' => 'export_import_operations',
    ],
    'storage' => [
        'disk' => 'local',
        'directory' => 'export-import',
    ],
    'queue' => [
        'connection' => null,
        'name' => null,
    ],
    'export' => [
        'writers' => [
            'csv' => TabularExportWriter::class,
            'xlsx' => TabularExportWriter::class,
        ],
    ],
    'download' => [
        'route' => null,
        'parameter' => 'operation',
        'expiration_minutes' => 15,
    ],
];
