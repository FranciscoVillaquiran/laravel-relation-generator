<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Frontend\TemplateInstaller;
use Illuminate\Console\Command;
use Throwable;

class FrontendTemplateCommand extends Command
{
    protected $signature = 'lrg:frontend:template {--path= : Path to the sibling React project}';

    protected $description = 'Install the LRG React template into an existing sibling Vite project';

    public function handle(): int
    {
        try {
            $version = $this->laravel->version();
            if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID >= 90000
                || preg_match('/^(\d+)\./', $version, $match) !== 1
                || !in_array((int) $match[1], [10, 11, 12, 13], true)) {
                throw new \RuntimeException('The installed PHP or Laravel version is outside the package compatibility range.');
            }
            $installer = new TemplateInstaller(base_path());
            $plan = $installer->prepare((string) $this->option('path'));
            if ($plan['recovered']) {
                $this->warn('Recovered a previously interrupted frontend installation.');
            }
            if ($plan['installed']) {
                $this->info('The LRG React template is already installed and unchanged.');
                return self::SUCCESS;
            }
            $this->line("Frontend: {$plan['root']}");
            $this->warn("The recognized Vite starter {$plan['entry']} will be replaced.");
            foreach (array_keys($plan['changes']) as $path) {
                $this->line("  {$path}");
            }
            if (!$this->confirm('Apply these changes?', false)) {
                $this->warn('Installation cancelled; no template files were written.');
                return self::FAILURE;
            }
            $installer->install($plan);
            $this->info('LRG React template installed in demonstration mode.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
