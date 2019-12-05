<?php
/**
 * This Class models the EnviroSensorData model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorData Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorData extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_data';

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

    public function enviro_sensor_module(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModule');
    }
}