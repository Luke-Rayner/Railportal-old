<?php
/**
 * This Class models the EnviroSensorModule model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorModule Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorModule extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_module';

    protected $fillable = [
        "enviro_sensor_id",
        "key_name",
        "enviro_sensor_module_type_id"
    ];

    /**
     * @var bool Enable timestamps for Sensors.
     */
    public $timestamps = true;

    /**
     * returns all sensor data for this sensor
     */
    public function enviro_sensor_data(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorData');
    }

    /**
     * Get the module type for this module
     */
    public function enviro_sensor_module_type(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModuleType');
    }

    /**
     * Return the enviro sensor for this module
     */
    public function enviro_sensor(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor');
    }
}