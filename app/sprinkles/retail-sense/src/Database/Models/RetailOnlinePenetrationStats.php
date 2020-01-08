<?php
/**
 * This Class models the RetailOnlinePenetrationStats model following the table definition
 */
namespace UserFrosting\Sprinkle\RetailSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * RetailOnlinePenetrationStats Class
 *
 * @package RetailSense
 * @author Luke Rayner/ElephantWiFi
 */
class RetailOnlinePenetrationStats extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'retail_online_penetration_stats';

    protected $fillable = [
        'day_epoch',
        'date',
        'all_retailing_excluding_automotive_fuel',
        'predominantly_food_stores_total',
        'predominantly_non_food_stores_total',
        'non_specialised_stores',
        'textile_clothing_footwear_stores',
        'household_goods_stores',
        'other_stores',
        'non_store_retailing'  
    ];
}