<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Frontend\FrontendConnector;
use Illuminate\Console\Command;
use Throwable;

class FrontendConnectCommand extends Command
{
    protected $signature = 'lrg:frontend:connect {--path= : Path to the sibling React project}';

    protected $description = 'Connect or resynchronize an installed LRG React frontend';

    public function handle(): int
    {
        try {
            $version = $this->laravel->version();
            if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID >= 90000
                || preg_match('/^(\d+)\./', $version, $match) !== 1
                || !in_array((int) $match[1], [10, 11, 12, 13], true)) {
                throw new \RuntimeException('The installed PHP or Laravel version is outside the package compatibility range.');
            }
            $url = config('app.url');
            if (!is_string($url)) throw new \RuntimeException('Laravel app.url must be configured as an absolute HTTP(S) URL.');
            $connector = new FrontendConnector(base_path());
            $plan = $connector->prepare((string) $this->option('path'), $url);
            $changed = $connector->apply($plan);
            $this->info($changed ? 'LRG frontend connection configured.' : 'LRG frontend connection is already synchronized.');
            $prefix = $plan['prefix'] === '' ? '(unprefixed registered endpoints)' : $plan['prefix'];
            $this->line("Development proxy: {$prefix} -> {$plan['origin']}");
            $this->warn('Configuration was verified; backend HTTP connectivity was not tested. Restart Vite.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
