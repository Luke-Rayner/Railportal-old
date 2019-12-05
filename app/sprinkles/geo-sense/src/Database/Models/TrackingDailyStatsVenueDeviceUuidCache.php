<?php
/**
 * This Class models this stats model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;

/**
 * TrackingDailyStatsVenueDeviceUuidCache Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsVenueDeviceUuidCache extends UserFrosting\Sprinkle\Core\Database\Models\Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_venue_device_uuid_cache';

    protected $fillable = [
        'device_uuid',
        'venue_id',
        'first_seen',
        'prev_last_seen',
        'last_seen',
        'probe_request_count'
    ];

    /**
     * accessor/mutator for the device_uuid attribute
     * https://laravel.com/docs/5.5/eloquent-mutators#accessors-and-mutators
     */
    public function setDeviceUuidAttribute($value){
        $this->attributes['device_uuid'] = hex2bin($value);
    }

    public function getDeviceUuidAttribute($value){
        return bin2hex($value);
    }

    /**
     * Get the venue a stats entry belongs to.
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}