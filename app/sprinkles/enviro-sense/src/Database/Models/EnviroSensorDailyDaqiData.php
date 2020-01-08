<?php
/**
 * This Class models the RetailMonthlyStats model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorDailyDaqiData Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorDailyDaqiData extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_daily_daqi_data';

    protected $fillable = [
        "day_epoch",
        "enviro_sensor_id",
        "venue_id",
        "daqi_value"
    ];

    public function enviro_sensor(){
        return $this->belongsTo('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor');
    }

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}