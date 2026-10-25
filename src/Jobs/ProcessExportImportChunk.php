<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ExportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\TabularExportWriter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class ProcessExportImportChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 20;

    public int $timeout = 80;

    public function __construct(
        public int $operationId,
        public int $chunkNumber,
        public ?int $lowerId,
        public int $upperId,
    ) {}

    /**
     * Prevents duplicate workers from writing the same operation chunk concurrently.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-import:{$this->operationId}:{$this->chunkNumber}"))
                ->releaseAfter(5)
                ->expireAfter(900),
        ];
    }

    /**
     * Defines retry delays for transient failures while generating this chunk.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(TabularExportWriter $writer): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);
        if ($operation->status !== ExportImportStatus::Processing) {
            return;
        }

        $exporter = app($operation->handler);

        if (! $exporter instanceof ExportHandler) {
            throw new LogicException('Export handler must implement ExportHandler.');
        }

        $idColumn = $exporter->idColumn();
        $query = $exporter->query($operation);
        if ($this->lowerId !== null) {
            $query->where($idColumn, '>', $this->lowerId);
        }

        $query->where($idColumn, '<=', $this->upperId);

        $writer->writeChunk(
            $operation,
            $this->chunkNumber,
            $query,
            $idColumn,
            $exporter->mapRecord(...),
        );
    }

    public function failed(?Throwable $exception): void
    {
        ExportImportOperation::newConfiguredQuery()->whereKey($this->operationId)->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception?->getMessage() ?? 'Export chunk failed.', 0, 5000),
            'completed_at' => now(),
            'active_export_key' => null,
        ]);
        $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');
        Storage::disk((string) config('export-import.storage.disk', 'local'))
            ->deleteDirectory("{$directory}/exports/{$this->operationId}");
    }
}
