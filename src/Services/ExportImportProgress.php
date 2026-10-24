<?php

namespace Agung\LaravelExportImport\Services;

use Agung\LaravelExportImport\ExportImportOperation;

final class ExportImportProgress
{
    public function __construct(private ExportImportOperation $operation) {}

    public function setTotal(int $total): void
    {
        $this->operation->forceFill(['total_rows' => $total])->save();
    }

    public function advance(int $rows = 1): void
    {
        $this->operation->increment('processed_rows', $rows);
    }
}
