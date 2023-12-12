<?php
/**
 * ServiceProvider.php
 * Date: 16.05.2017
 * Time: 15:51
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Providers;

use Illuminate\Support\ServiceProvider;
use Route;

class ProtocolProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishResources();
        $this->registerRoute();
    }

    /**
     * Публикация ресурсов
     */
    private function publishResources()
    {
        // конфигурационный файл
        $this->publishes([
            __DIR__
            .'/../../config/protocolExchange1C.php' => config_path('protocolExchange1C.php'),
        ], 'la1CProtocolConfig');

        $this->mergeConfigFrom(
            __DIR__.'/../../config/protocolExchange1C.php',
            'protocolExchange1C'
        );
    }

    /**
     * Регистрация роута
     */
    private function registerRoute()
    {
        Route::group(
            [
                'namespace'  => 'Mavsan\LaProtocol\Http\Controllers',
                'middleware' => config('protocolExchange1C.middleware'),
            ],
            function () {
                Route::match(
                    ['get', 'post'],
                    config('protocolExchange1C.1cRouteNameCatalog'),
                    'CatalogController@catalogIn'
                )->name('1sProtocolCatalog');
            }
        );
    }
}
