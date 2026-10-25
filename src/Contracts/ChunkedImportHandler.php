<?php

namespace Agung\LaravelExportImport\Contracts;

use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportChunkSizer;
use Agung\LaravelExportImport\Services\ExportImportProgress;

interface ChunkedImportHandler extends ImportHandler
{
    /**
     * Counts input rows and stages bounded chunks before the engine dispatches validation jobs.
     */
    public function prepareChunks(ExportImportOperation $operation, ExportChunkSizer $chunkSizer): int;

    /**
     * Validates one staged chunk without writing imported records.
     */
    public function validateChunk(ExportImportOperation $operation, int $chunkNumber): void;

    /**
     * Processes one prepared chunk and advances progress after its records are committed.
     */
    public function handleChunk(
        ExportImportOperation $operation,
        int $chunkNumber,
        ExportImportProgress $progress,
    ): void;

    /**
     * Removes temporary chunk files when the operation reaches a terminal state.
     */
    public function cleanupChunks(ExportImportOperation $operation): void;
}
