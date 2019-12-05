<?php
/**
 * This Class models the WifiDailyStatsVenueWebTitanPerDay model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueWebTitanPerDay Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueWebTitanPerDay extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_web_titan_per_day';

    protected $fillable = [
        'venue_id',
        'day_epoch',
        'blocked_domains',
        'allowed_domains',
        'blocked_categories',
        'allowed_categories'
    ];

    /**
     * Get the venue this belongs to
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}