<?php
/**
 * This Class models this stats model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsVenueUniqueDeviceUuids Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsVenueUniqueDeviceUuids extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_venue_unique_device_uuids_per_hour';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'hour',
        'venue_id',
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
     * Get the device_vendor a stats entry belongs to.
     */
    public function device_vendor(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}