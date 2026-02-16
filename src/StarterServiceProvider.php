<?php

namespace Artworksit\Starter;

use Illuminate\Support\ServiceProvider;
use Artworksit\Starter\Console\BuildTypographyCommand;
use Artworksit\Starter\Console\GenerateOgImageCommand;
use Artworksit\Starter\Console\GenerateSitemapCommand;
use Artworksit\Starter\Console\InstallCommand;

class StarterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/starter.php', 'starter');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/starter.php' => config_path('starter.php'),
            ], 'starter-config');

            $this->commands([
                BuildTypographyCommand::class,
                GenerateOgImageCommand::class,
                GenerateSitemapCommand::class,
                InstallCommand::class,
            ]);
        }
    }
}
