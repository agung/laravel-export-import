<?php

use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;

it('returns indeterminate progress when total rows are not known', function (?int $totalRows) {
    $operation = new ExportImportOperation;
    $operation->forceFill([
        'status' => ExportImportStatus::Processing,
        'processed_rows' => 0,
        'total_rows' => $totalRows,
    ]);

    expect($operation->progressPercentage())->toBeNull();
})->with([null, 0]);

it('calculates row progress to hundredth precision', function () {
    $operation = new ExportImportOperation;
    $operation->forceFill([
        'status' => ExportImportStatus::Processing,
        'processed_rows' => 1,
        'total_rows' => 300,
    ]);

    expect($operation->progressPercentage())->toBe(0.33);
});

it('does not report full progress before operation completion', function () {
    $operation = new ExportImportOperation;
    $operation->forceFill([
        'status' => ExportImportStatus::Processing,
        'processed_rows' => 100,
        'total_rows' => 100,
    ]);

    expect($operation->progressPercentage())->toBe(99.99);
});

it('reports full progress after operation completion', function () {
    $operation = new ExportImportOperation;
    $operation->forceFill([
        'status' => ExportImportStatus::Completed,
        'processed_rows' => 0,
        'total_rows' => null,
    ]);

    expect($operation->progressPercentage())->toBe(100.0);
});
