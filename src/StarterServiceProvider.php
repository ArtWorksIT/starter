<?php

namespace Artworksit\Starter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
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
        Blueprint::macro('publishable', function (): void {
            /** @var Blueprint $this */
            $this->timestamp('published_at')->nullable()->index();
        });

        Blueprint::macro('dropPublishable', function (): void {
            /** @var Blueprint $this */
            $this->dropColumn('published_at');
        });

        Builder::macro('publishedOrFail', function (): mixed {
            /** @var Builder $this */
            $record = $this->firstOrFail();

            if (! method_exists($record, 'isAccessibleByUser')) {
                return $record;
            }

            $record->abortIfNotAccessible();

            return $record;
        });

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
