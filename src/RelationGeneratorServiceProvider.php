<?php

namespace Franciscovillaquiran\LaravelRelationGenerator;

use Illuminate\Support\ServiceProvider;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;

class RelationGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RelationMakeCommand::class,
            ]);
        }
    }
}