<?php

namespace Agung\LaravelExportImport\Contracts;

use Agung\LaravelExportImport\ExportImportOperation;

interface ExportFileWriter
{
    /**
     * Builds the final export file from the operation's completed chunks.
     *
     * @param  list<string>  $headers
     * @param  iterable<string>  $chunkPaths
     */
    public function finalize(ExportImportOperation $operation, array $headers, iterable $chunkPaths): string;
}
