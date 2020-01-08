<?php
/**
 * This Class models the Mac model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * DroneHealth Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class DroneHealth extends Model 
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'drone_health';

    protected $fillable = [
        'timestamp',
        'drone_id',
        'load_average',
        'wlan',
        'temp',
        'uptime',
        'timestamp_stored'
    ];

    public function drone(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\Drone');
    }
}