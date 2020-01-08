<?php
/**
 * This Class models the RetailMonthlyStats model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensor Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensor extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor';

    protected $fillable = [
        "venue_id",
        "connection_type",
        "serial_id",
        "name",
        "status",
        "versionsw",
        "versionhw",
        "lat",
        "lon",
        "life_indicator"
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * returns all sensor data for this enviro_sensor
     */
    public function enviro_sensor_data(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorData');
    }

    /**
     * returns all the sensor latest_captures for this enviro_sensor
     */
    public function enviro_sensor_latest_capture(){
        return $this->hasOne('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorData')->orderBy('ts', 'DESC');
    }

    /**
     * returns all sensor daqi data for this enviro_sensor
     */
    public function enviro_sensor_daqi_data(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorDailyDaqiData');
    }

    /**
     * returns all users that have access to this venue
     *
     * Many to Many requires this type of relationship (belongsToMany) on both ends
     */
    public function enviro_sensor_modules(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModule');
    }

    /**
     * Get timestamp of the latest data submitted (not necessarily stored) by this enviro sensor
     */
    public function last_activity(){
        return $this->hasOne('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorLatestCapture');
    }
}