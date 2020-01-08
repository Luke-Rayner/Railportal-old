<?php
/**
 * This Class models the EnviroSensorHourlyAqiData model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorHourlyAqiData Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorHourlyAqiData extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_hourly_aqi_data';

    protected $fillable = [
        "ts",
	    "enviro_sensor_id",
	    "particle_matter_2_5_aqi",
	    "particle_matter_2_5_value",
	    "particle_matter_10_aqi",
	    "particle_matter_10_value",
	    "ozone_aqi",
	    "ozone_value",
	    "nitrogen_dioxide_aqi",
	    "nitrogen_dioxide_value",
	    "sulfur_dioxide_aqi",
	    "sulfur_dioxide_value"
    ];

    public function enviro_sensor(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensors');
    }
}