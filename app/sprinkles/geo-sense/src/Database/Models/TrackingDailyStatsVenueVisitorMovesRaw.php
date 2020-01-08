<?php
/**
 * This Class models this stats model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;

/**
 * TrackingDailyStatsVenueVisitorMovesRaw Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsVenueVisitorMovesRaw extends UserFrosting\Sprinkle\Core\Database\Models\Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_venue_visitor_moves_raw';

    protected $fillable = [
        'arrival',
        'from_drone_id',
        'to_drone_id',
        'travel_time',
        'device_uuid',
        'venue_id'
    ];

    /**
     * Get the venue a stats entry belongs to.
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}