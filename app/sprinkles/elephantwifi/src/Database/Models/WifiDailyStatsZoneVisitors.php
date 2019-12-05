<?php
/**
 * This Class models the WifiDailyStatsZoneVisitors model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsZoneVisitors Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsZoneVisitors extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_zone_client_per_day';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'venue_id',
        'zone_id',
        'total_device_uuid',
        'new_device_uuid',
        'authorised_device_uuid',
        'has_authorised_device_uuid'
    ];

    /**
    * Get the zone a stats entry belongs to.
    */
    public function zone(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }

    /**
    * Get the venue a stats entry belongs to.
    */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

}