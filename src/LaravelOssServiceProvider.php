<?php

namespace mradang\LaravelDingtalk;

use Illuminate\Support\ServiceProvider;
use OSS\OssClient;

class LaravelOssServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->mergeConfigFrom(realpath(__DIR__.'/../config/config.php'), 'oss');

        $this->app->singleton('oss', function ($app) {
            $config = $app->config->get('oss');
            return new OssClient(
                $config['id'],
                $config['key'],
                $config['endpoint']
            );
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                realpath(__DIR__.'/../config/config.php') => config_path('oss.php')
            ], 'config');
            $this->publishes([
                realpath(__DIR__.'/../migrations') => database_path('migrations')
            ], 'migrations');
        }
    }

}