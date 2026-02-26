<?php

namespace App\Http\Controllers\V1\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * APP 设备管理控制器
 */
class AppController extends Controller
{
    /**
     * 获取用户 (通过token验证)
     */
    private function getUser(Request $request)
    {
        $token = $request->input('token');
        if (!$token) {
            return null;
        }
        return User::where('token', $token)->first();
    }

    /**
     * 验证用户并返回
     */
    private function validateUser(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            abort(500, '用户信息错误');
        }
        if ($user->banned) {
            abort(500, '此账号已被停用');
        }
        return $user;
    }

    // ==========================================
    // 设备管理接口
    // ==========================================

    /**
     * 处理设备绑定（内部方法）
     */
    private function handleDeviceBind($user, $request)
    {
        $deviceId = $request->input('device_id');
        if (empty($deviceId)) {
            return ['status' => 1, 'msg' => '无设备信息'];
        }

        $deviceLimit = $user->device_limit ?? 0;
        if ($deviceLimit > 0) {
            $currentDeviceCount = UserDevice::getActiveDeviceCount($user->id);
            $isDeviceBound = UserDevice::isDeviceBound($user->id, $deviceId);

            if (!$isDeviceBound && $currentDeviceCount >= $deviceLimit) {
                return [
                    'status' => 0,
                    'msg' => "设备数已达上限({$currentDeviceCount}/{$deviceLimit})，请先解绑其他设备",
                    'data' => [
                        'device_count' => $currentDeviceCount,
                        'device_limit' => $deviceLimit,
                        'need_unbind' => true
                    ]
                ];
            }
        }

        $deviceInfo = [
            'device_id' => $deviceId,
            'device_name' => $request->input('device_name'),
            'device_model' => $request->input('device_model'),
            'os_type' => $request->input('os_type'),
            'os_version' => $request->input('os_version'),
            'app_version' => $request->input('app_version'),
        ];

        try {
            UserDevice::bindDevice($user->id, $deviceInfo, $request->ip());
            return ['status' => 1, 'msg' => '设备绑定成功'];
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '设备绑定失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取用户绑定设备列表
     * GET /api/v1/app/device/list
     */
    public function deviceList(Request $request)
    {
        $user = $this->validateUser($request);

        $devices = UserDevice::getUserDevices($user->id);
        $deviceLimit = $user->device_limit ?? 0;

        $formattedDevices = $devices->map(function ($device) {
            return [
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'device_model' => $device->device_model,
                'os_type' => $device->os_type,
                'os_version' => $device->os_version,
                'app_version' => $device->app_version,
                'last_active_at' => $device->last_active_at,
                'last_active_date' => $device->last_active_at ? date('Y-m-d H:i:s', $device->last_active_at) : null,
                'last_ip' => $device->last_ip,
                'created_at' => $device->created_at,
            ];
        });

        return response()->json([
            'status' => 1,
            'msg' => 'Success',
            'data' => [
                'devices' => $formattedDevices,
                'count' => $devices->count(),
                'limit' => $deviceLimit
            ]
        ]);
    }

    /**
     * 绑定设备
     * POST /api/v1/app/device/bind
     */
    public function deviceBind(Request $request)
    {
        $user = $this->validateUser($request);

        $deviceId = $request->input('device_id');
        if (empty($deviceId)) {
            return response()->json(['status' => 0, 'msg' => '设备标识不能为空']);
        }

        $result = $this->handleDeviceBind($user, $request);
        return response()->json($result);
    }

    /**
     * 解绑设备
     * POST /api/v1/app/device/unbind
     */
    public function deviceUnbind(Request $request)
    {
        $user = $this->validateUser($request);

        $deviceId = $request->input('device_id');
        if (empty($deviceId)) {
            return response()->json(['status' => 0, 'msg' => '设备标识不能为空']);
        }

        $device = UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->where('status', 1)
            ->first();

        if (!$device) {
            return response()->json(['status' => 0, 'msg' => '设备不存在或已解绑']);
        }

        $success = UserDevice::unbindDevice($user->id, $deviceId);

        if ($success) {
            $newCount = UserDevice::getActiveDeviceCount($user->id);
            return response()->json([
                'status' => 1,
                'msg' => '设备解绑成功',
                'data' => [
                    'device_count' => $newCount,
                    'device_limit' => $user->device_limit ?? 0
                ]
            ]);
        }

        return response()->json(['status' => 0, 'msg' => '解绑失败，请重试']);
    }

    /**
     * 解绑所有设备（除当前设备外）
     * POST /api/v1/app/device/unbind-all
     */
    public function deviceUnbindAll(Request $request)
    {
        $user = $this->validateUser($request);

        $keepDeviceId = $request->input('keep_device_id');

        $query = UserDevice::where('user_id', $user->id)->where('status', 1);

        if ($keepDeviceId) {
            $query->where('device_id', '!=', $keepDeviceId);
        }

        $unbindCount = $query->update(['status' => 0, 'updated_at' => time()]);

        $newCount = UserDevice::getActiveDeviceCount($user->id);

        return response()->json([
            'status' => 1,
            'msg' => "成功解绑 {$unbindCount} 个设备",
            'data' => [
                'unbind_count' => $unbindCount,
                'device_count' => $newCount,
                'device_limit' => $user->device_limit ?? 0
            ]
        ]);
    }
}
