<?php

namespace Agung\LaravelExportImport\Contracts;

use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportImportProgress;

interface ExportImportHandler
{
    public function handle(ExportImportOperation $operation, ExportImportProgress $progress): void;
}
