<?php
/**
 * This Class models the WifiDailyStatsVenueUniqueDeviceUuids model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueUniqueDeviceUuids Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueUniqueDeviceUuids extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_unique_device_uuids_per_hour';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'hour',
        'venue_id',
        'device_uuid',
        'first_seen',
        'last_seen',
        'is_repeat',
        'is_authorised',
        'has_authorised',
        'age',
        'gender',
        'postcode',
        'provider'
    ];

    /**
     * Get the venue a stats entry belongs to.
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}