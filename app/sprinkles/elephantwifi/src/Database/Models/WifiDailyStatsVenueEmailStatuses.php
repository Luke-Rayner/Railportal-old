<?php
/**
 * This Class models the WifiDailyStatsVenueEmailStatuses model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WifiDailyStatsVenueEmailStatuses Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class WifiDailyStatsVenueEmailStatuses extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'wifi_daily_stats_venue_email_statuses';

    protected $fillable = [
        'venue_id',
        'day_epoch',
        'valid',
        'invalid',
        'catch_all',
        'unknown',
        'emails_sent'
    ];

    /**
    * Get the venue a stats entry belongs to.
    */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}