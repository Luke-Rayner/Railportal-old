<?php
/**
 * This Class models the EnviroSensorHourlyData model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorHourlyData Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorHourlyData extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_hourly_data';

    protected $fillable = [
        "ts",
	    "enviro_sensor_id",
	    "temperature",
	    "pressure",
	    "humidity",
	    "voc_raw",
	    "voc_aqi",
	    "noise",
	    "particle_matter_1",
	    "particle_matter_2_5",
	    "particle_matter_10",
	    "ozone",
	    "nitrogen_dioxide",
	    "sulfur_dioxide",
	    "carbon_monoxide"
    ];

    public function enviro_sensor(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensors');
    }
}