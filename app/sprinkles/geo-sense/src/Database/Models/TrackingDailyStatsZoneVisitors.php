<?php
/**
 * This Class models the TrackingDailyStatsZoneVisitors model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsZoneVisitors Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsZoneVisitors extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_zone_visitors';

    protected $fillable = [
        'day_epoch',
        'day_of_week',
        'venue_id',
        'zone_id',
        'rv_level_1',
        'rv_level_2',
        'rv_level_3',
        'rv_level_4',
        'rv_level_5',
        'rv_level_6',
        'rv_level_7',
        'visitors_new',
        'visitors_total'
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