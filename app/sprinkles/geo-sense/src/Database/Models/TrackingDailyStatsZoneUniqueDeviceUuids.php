<?php
/**
 * This Class models the TrackingDailyStatsZoneUniqueDeviceUuids model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsZoneUniqueDeviceUuids Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsZoneUniqueDeviceUuids extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_zone_unique_device_uuids_per_hour';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'hour',
        'venue_id',
        'zone_id',
        'device_uuid',
        'device_vendor_id',
        'first_seen',
        'last_seen',
        'is_repeat',
        'prev_last_seen',
        'visitor_profile_id'
    ];

    /**
     * Get the venue a stats entry belongs to.
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * Get the zone a stats entry belongs to.
     */
    public function zone(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }

    /**
     * Get the device_vendor a stats entry belongs to.
     */
    public function device_vendor(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}