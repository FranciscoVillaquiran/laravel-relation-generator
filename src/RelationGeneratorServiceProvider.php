<?php

namespace Franciscovillaquiran\LaravelRelationGenerator;

use Illuminate\Support\ServiceProvider;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\FrontendTemplateCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\FrontendConnectCommand;

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
                FrontendTemplateCommand::class,
                FrontendConnectCommand::class,
            ]);
        }
    }
}