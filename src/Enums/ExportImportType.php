<?php

namespace Agung\LaravelExportImport\Enums;

enum ExportImportType: string
{
    case Export = 'export';
    case Import = 'import';
}
