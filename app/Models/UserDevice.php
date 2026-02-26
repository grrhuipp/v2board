<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $table = 'v2_user_device';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    const STATUS_UNBOUND = 0;
    const STATUS_BOUND = 1;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 检查设备是否已绑定
     */
    public static function isDeviceBound($userId, $deviceId)
    {
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('status', self::STATUS_BOUND)
            ->exists();
    }

    /**
     * 获取用户活跃设备数量
     */
    public static function getActiveDeviceCount($userId)
    {
        return self::where('user_id', $userId)
            ->where('status', self::STATUS_BOUND)
            ->count();
    }

    /**
     * 获取用户所有活跃设备
     */
    public static function getUserDevices($userId)
    {
        return self::where('user_id', $userId)
            ->where('status', self::STATUS_BOUND)
            ->orderBy('last_active_at', 'desc')
            ->get();
    }

    /**
     * 绑定设备（已存在则更新，不存在则创建）
     */
    public static function bindDevice($userId, $deviceInfo, $ip = null)
    {
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'device_id' => $deviceInfo['device_id'],
            ],
            [
                'device_name' => $deviceInfo['device_name'] ?? null,
                'device_model' => $deviceInfo['device_model'] ?? null,
                'os_type' => $deviceInfo['os_type'] ?? null,
                'os_version' => $deviceInfo['os_version'] ?? null,
                'app_version' => $deviceInfo['app_version'] ?? null,
                'last_ip' => $ip,
                'status' => self::STATUS_BOUND,
                'last_active_at' => time(),
            ]
        );
    }

    /**
     * 解绑设备
     */
    public static function unbindDevice($userId, $deviceId)
    {
        return (bool) self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->update(['status' => self::STATUS_UNBOUND]);
    }
}
