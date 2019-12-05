<?php
/**
 * This Class models the WifiDailyStatsVenueWeather model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueWeather Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueWeather extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_weather';

    protected $fillable = [
        'day_epoch',
        'venue_id',
        'temperature_max',
        'temperature_min',
        'wind_bearing',
        'wind_speed',
        'pressure',
        'precip_total',
        'icon',
        'summary'
    ];

    /**
    * Get the venue a stats entry belongs to.
    */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}