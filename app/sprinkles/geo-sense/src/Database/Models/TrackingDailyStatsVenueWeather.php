<?php
/**
 * This Class models the TrackingDailyStatsVenueWeather model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsVenueWeather Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsVenueWeather extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_venue_weather';

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