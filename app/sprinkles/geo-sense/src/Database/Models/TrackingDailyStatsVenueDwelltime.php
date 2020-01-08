<?php
/**
 * This Class models this stats model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * TrackingDailyStatsVenueDwelltime Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class TrackingDailyStatsVenueDwelltime extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tracking_daily_stats_venue_visitor_dwelltime';

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
     * Get the venue a stats entry belongs to.
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}