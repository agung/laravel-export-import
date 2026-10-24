<?php

namespace Agung\LaravelExportImport;

use Illuminate\Support\ServiceProvider;

class ExportImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/export-import.php', 'export-import');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');

        $this->publishes([
            dirname(__DIR__).'/config/export-import.php' => config_path('export-import.php'),
        ], 'export-import-config');
    }
}
