<?php

namespace Agung\LaravelExportImport\Services;

use Agung\LaravelExportImport\Contracts\ExportFileWriter;
use InvalidArgumentException;
use LogicException;

final class ExportFileWriterManager
{
    /**
     * Resolves the configured final file writer for an export format.
     */
    public function forFormat(string $format): ExportFileWriter
    {
        $writers = config('export-import.export.writers', []);
        $writerClass = is_array($writers) ? ($writers[$format] ?? null) : null;

        if (! is_string($writerClass) || $writerClass === '') {
            throw new InvalidArgumentException("No export writer is configured for format [{$format}].");
        }

        $writer = app($writerClass);

        if (! $writer instanceof ExportFileWriter) {
            throw new LogicException('Configured export writer must implement ExportFileWriter.');
        }

        return $writer;
    }
}
