<?php
/**
 * This Class models the WifiDailyStatsVenueVisitors model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueVisitors Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueVisitors extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_client_per_day';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'venue_id',
        'total_device_uuid',
        'new_device_uuid',
        'authorised_device_uuid',
        'has_authorised_device_uuid'
    ];

    /**
    * Get the venue a stats entry belongs to.
    */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}