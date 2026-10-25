<?php

namespace Agung\LaravelExportImport;

use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\Enums\ExportImportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

#[Fillable([
    'type', 'status', 'handler', 'format', 'disk', 'file_path', 'file_name', 'payload',
    'processed_rows', 'total_rows', 'error_message', 'started_at', 'completed_at', 'actor_id',
    'actor_type', 'context', 'active_export_key', 'batch_id',
])]
class ExportImportOperation extends Model
{
    /**
     * Creates a query builder using the application's configured operation model.
     *
     * @return Builder<static>
     */
    public static function newConfiguredQuery(): Builder
    {
        $modelClass = config('export-import.models.operation', self::class);
        if (! is_string($modelClass) || ! is_a($modelClass, self::class, true)) {
            throw new InvalidArgumentException(
                'The configured export/import operation model must extend ExportImportOperation.',
            );
        }

        /** @var static $model */
        $model = new $modelClass;

        return $model->newQuery();
    }

    public static function query(): Builder
    {
        return self::newConfiguredQuery();
    }

    public function getTable(): string
    {
        return (string) config('export-import.tables.operations', 'export_import_operations');
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function progressPercentage(): ?float
    {
        if ($this->status === ExportImportStatus::Completed) {
            return 100.0;
        }

        if ($this->batch_id) {
            $batch = Bus::findBatch($this->batch_id);

            if (! $batch) {
                return null;
            }

            $batchProgress = (float) $batch->progress();
            $phase = $this->context['import_phase'] ?? null;

            if ($phase === 'validating') {
                return min(99.99, $batchProgress / 2);
            }

            if ($phase === 'processing') {
                return min(99.99, 50 + ($batchProgress / 2));
            }

            return min(99.99, $batchProgress);
        }

        if ($this->total_rows === null || $this->total_rows === 0) {
            return null;
        }

        return min(99.99, floor(($this->processed_rows / $this->total_rows) * 10000) / 100);
    }

    protected function casts(): array
    {
        return [
            'type' => ExportImportType::class,
            'status' => ExportImportStatus::class,
            'payload' => 'array',
            'context' => 'array',
            'processed_rows' => 'integer',
            'total_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
