<?php

use Agung\LaravelExportImport\ExportImportOperation;

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
    'download' => [
        'route' => null,
        'parameter' => 'operation',
        'expiration_minutes' => 15,
    ],
];
