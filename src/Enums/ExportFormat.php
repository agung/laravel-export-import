<?php

namespace Agung\LaravelExportImport\Enums;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
