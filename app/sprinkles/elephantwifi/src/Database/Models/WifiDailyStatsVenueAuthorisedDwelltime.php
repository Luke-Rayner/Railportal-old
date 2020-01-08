<?php
/**
 * This Class models the WifiDailyStatsVenueAuthorisedDwelltime model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueAuthorisedDwelltime Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueAuthorisedDwelltime extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_authorised_visitor_dwelltime';

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