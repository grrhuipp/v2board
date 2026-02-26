<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

/**
 * APP 设备管理路由
 *
 * API 前缀: /api/v1/jiuxiang
 */
class AppClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'jiuxiang'
        ], function ($router) {
            // ========== 设备管理 ==========
            $router->get('/device/list', 'V1\\AppClient\\AppController@deviceList');
            $router->post('/device/bind', 'V1\\AppClient\\AppController@deviceBind');
            $router->post('/device/unbind', 'V1\\AppClient\\AppController@deviceUnbind');
            $router->post('/device/unbind-all', 'V1\\AppClient\\AppController@deviceUnbindAll');
        });
    }
}
