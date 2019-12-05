<?php
/**
 * This Class models the TrackingDailyStatsZoneDwelltime model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsZoneDwelltime Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsZoneDwelltime extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_zone_visitor_dwelltime';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'venue_id',
        'dt_skipped',
        'dt_level_1',
        'dt_level_2',
        'dt_level_3',
        'dt_level_4',
        'dt_level_5',
        'dt_average'
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