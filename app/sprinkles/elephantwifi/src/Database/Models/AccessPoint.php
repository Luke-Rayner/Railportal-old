<?php
/**
 * This Class models the AccessPoint model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * AccessPoint Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class AccessPoint extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'access_point';

    protected $fillable = [
        'zone_id',
        'ap_uuid',
        'mac',
        'state',
        'model',
        'serial',
        'board_rev'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Get all wifi_client_connections for this ap.
     */
    public function wifi_client_connections() {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiClientConnections');
    }

    /**
     * Get the most recent stored wifi_client_connection for this access_point.
     */
    public function lastWifiClientConnection() {
        return $this->wifi_client_connections()
        ->where('access_point_id')
        ->orderBy('ts', 'desc')
        ->first();
    }

    /**
     * Get all the ap_configs for this ap.
     */
    public function ap_configs() {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\ApConfig');
    }

    /**
     * Get the most recent stored ap_config for this ap.
     */
    public function lastApConfig() {
        return $this->ap_configs()
        ->orderBy('created_at', 'desc')
        ->first();
    }

    /**
     * Get the zone this access_point belongs to.
     */
    public function zone() {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }
}