<?php
/**
 * This Class models the DroneLatestCapture model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * DroneLatestCapture Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class DroneLatestCapture extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'drone_latest_capture';

    protected $fillable = [
        'timestamp',
        'drone_id'
    ];

    public function drone(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\Drone');
    }
}