<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ChunkedImportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LogicException;

class ValidateImportChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public int $operationId, public int $chunkNumber) {}

    /**
     * Prevents duplicate workers from validating the same import chunk concurrently.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-import:{$this->operationId}:validate:{$this->chunkNumber}"))
                ->releaseAfter(5)
                ->expireAfter(900),
        ];
    }

    /**
     * Defines retry delays for transient validation failures.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);

        if ($operation->status !== ExportImportStatus::Processing) {
            return;
        }

        $handler = app($operation->handler);

        if (! $handler instanceof ChunkedImportHandler) {
            throw new LogicException('Import handler must implement ChunkedImportHandler.');
        }

        $handler->validateChunk($operation, $this->chunkNumber);
    }
}
