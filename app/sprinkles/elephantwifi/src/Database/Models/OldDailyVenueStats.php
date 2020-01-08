<?php
/**
 * This Class models the OldDailyVenueStats model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * OldDailyVenueStats Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class OldDailyVenueStats extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'old_daily_venue_stats';

    protected $fillable = [
        'day_epoch',
        'venue_id',
        'total_count',
        'repeat_count',
        'male_count',
        'female_count',
        'unknown_gender_count',
        'facebook_count',
        'twitter_count',
        'form_count',
        'other_form_count',
        'range_0_9',
        'range_10_19',
        'range_20_29',
        'range_30_39',
        'range_40_49',
        'range_50_59',
        'range_60_69',
        'range_70_79',
        'range_80_89',
        'range_90_99',
        'range_100_plus',
        'range_unknown'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }
}