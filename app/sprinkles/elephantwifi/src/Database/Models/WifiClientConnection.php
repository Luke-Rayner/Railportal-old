<?php
/**
 * This Class models the WifiClientConnection model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiClientConnection Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiClientConnection extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_client_connection';

    protected $fillable = [
        'ts',
        'device_uuid',
        'access_point_id',
        'venue_id',
        'authorised'
    ];

    public function access_point(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\AccessPoint');
    }

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}