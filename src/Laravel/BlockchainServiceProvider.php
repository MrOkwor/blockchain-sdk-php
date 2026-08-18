<?php

namespace BlockchainSdk\Laravel;

use BlockchainSdk\BlockchainManager;
use BlockchainSdk\Laravel\Commands\MonitorCommand;
use BlockchainSdk\Laravel\Commands\SweepCommand;
use Illuminate\Support\ServiceProvider;

class BlockchainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/blockchainsdk.php',
            'blockchainsdk'
        );

        $this->app->singleton('blockchainsdk', function ($app) {
            return new BlockchainManager($app['config']['blockchainsdk'] ?? []);
        });

        $this->app->alias('blockchainsdk', BlockchainManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish Configuration
            $this->publishes([
                __DIR__ . '/../../config/blockchainsdk.php' => config_path('blockchainsdk.php'),
            ], 'blockchainsdk-config');

            // Publish Database Migrations
            $this->publishes([
                __DIR__ . '/../../database/migrations/create_blockchainsdk_wallets_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_blockchainsdk_wallets_table.php'),
                __DIR__ . '/../../database/migrations/create_blockchainsdk_deposits_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_blockchainsdk_deposits_table.php'),
                __DIR__ . '/../../database/migrations/create_blockchainsdk_sweeps_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_create_blockchainsdk_sweeps_table.php'),
            ], 'blockchainsdk-migrations');

            // Publish Eloquent Models to app/Models/
            $this->publishes([
                __DIR__ . '/../../stubs/BlockchainSdkWallet.stub'  => app_path('Models/BlockchainSdkWallet.php'),
                __DIR__ . '/../../stubs/BlockchainSdkDeposit.stub' => app_path('Models/BlockchainSdkDeposit.php'),
                __DIR__ . '/../../stubs/BlockchainSdkSweep.stub'   => app_path('Models/BlockchainSdkSweep.php'),
            ], 'blockchainsdk-models');

            // Register Artisan Commands
            $this->commands([
                SweepCommand::class,
                MonitorCommand::class,
            ]);
        }
    }
}