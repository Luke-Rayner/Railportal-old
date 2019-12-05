<?php
/**
 * This Class models the EnviroSensorLatestCapture model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorLatestCapture Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorLatestCapture extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_latest_capture';

    protected $fillable = [
        "timestamp",
    	"enviro_sensor_id"
    ];

    public function enviro_sensor(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor');
    }
}