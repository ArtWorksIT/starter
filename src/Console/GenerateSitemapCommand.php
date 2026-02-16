<?php

namespace Artworksit\Starter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'starter:sitemap:generate {--force : Overwrite generated command if it exists}';

    protected $description = 'Generate a sitemap:generate artisan command stub.';

    public function handle(Filesystem $filesystem): int
    {
        $targetPath = base_path('app/Console/Commands/GenerateSitemapCommand.php');

        if ($filesystem->exists($targetPath) && ! $this->option('force')) {
            $this->error('Command already exists at app/Console/Commands/GenerateSitemapCommand.php. Use --force to overwrite.');

            return self::FAILURE;
        }

        $stubPath = dirname(__DIR__, 2) . '/stubs/commands/GenerateSitemapCommand.stub';

        if (! $filesystem->exists($stubPath)) {
            $this->error('Sitemap command stub not found in package stubs.');

            return self::FAILURE;
        }

        $directory = dirname($targetPath);

        if (! $filesystem->isDirectory($directory)) {
            $filesystem->makeDirectory($directory, 0755, true);
        }

        $filesystem->put($targetPath, $filesystem->get($stubPath));

        $this->info('Generated app sitemap command at app/Console/Commands/GenerateSitemapCommand.php');
        $this->line('Run `php artisan sitemap:generate` to create sitemap.xml.');

        return self::SUCCESS;
    }
}
