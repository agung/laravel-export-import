<?php

namespace Agung\LaravelExportImport\Contracts;

use Agung\LaravelExportImport\ExportImportOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ExportHandler
{
    public function query(ExportImportOperation $operation): Builder;

    public function idColumn(): string;

    /**
     * Returns the ordered column headings for the exported file.
     *
     * @return list<string>
     */
    public function headers(): array;

    /**
     * Maps one queried model into an ordered row of export values.
     *
     * @return list<mixed>
     */
    public function mapRecord(Model $record): array;
}
