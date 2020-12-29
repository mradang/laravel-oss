<?php

namespace mradang\LaravelOss;

use Illuminate\Support\ServiceProvider;
use mradang\LaravelOss\Controllers\OssController;
use OSS\OssClient;

class LaravelOssServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(realpath(__DIR__ . '/../config/config.php'), 'oss');

        $this->app->singleton('oss', function ($app) {
            $config = $app->config->get('oss');
            return new OssClient(
                $config['id'],
                $config['key'],
                $config['endpoint'],
                true
            );
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                realpath(__DIR__ . '/../config/config.php') => config_path('oss.php')
            ], 'config');

            $this->loadMigrationsFrom(realpath(__DIR__ . '/../migrations/'));
        }

        $this->app->router->group([
            'prefix' => 'api/laravel_oss',
            'namespace' => 'mradang\LaravelOss\Controllers',
        ], function ($router) {
            $router->post('callback', [OssController::class, 'callback']);
        });
    }
}
