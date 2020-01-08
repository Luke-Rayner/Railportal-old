<?php
/**
 * This Class models the VenueTracking model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VenueTracking Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class VenueTracking extends Model
{
	/**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue_tracking';

    protected $fillable = [
        'venue_id',
        'capture_start',
        'event_info_bucket',
        'event_info_refresh',
        'event_info_zone_tag',
        'custom_map_file_uuid',
        'custom_map_file_name',
    ];

    /**
     * Get the venue this venue_tracking belongs to.
     */
    public function venue() {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}