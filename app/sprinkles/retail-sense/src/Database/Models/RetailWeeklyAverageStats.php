<?php
/**
 * This Class models the RetailWeeklyAverageStats model following the table definition
 */
namespace UserFrosting\Sprinkle\RetailSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * RetailWeeklyAverageStats Class
 *
 * @package RetailSense
 * @author Luke Rayner/ElephantWiFi
 */
class RetailWeeklyAverageStats extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'retail_weekly_average_stats';

    protected $fillable = [
        'day_epoch',
        'date',
        'all_retailing_including_automotive_fuel_all_businesses',
        'all_retailing_including_automotive_fuel_large_businesses',
        'all_retailing_including_automotive_fuel_small_businesses',
        'all_retailing_excluding_automotive_fuel_all_businesses',
        'all_retailing_excluding_automotive_fuel_large_businesses',
        'all_retailing_excluding_automotive_fuel_small_businesses',
        'predominantly_food_stores_total_all_businesses',
        'predominantly_food_stores_total_large_businesses',
        'predominantly_food_stores_total_small_businesses',
        'non_specialised_food_stores_all_businesses',
        'non_specialised_food_stores_large_businesses',
        'non_specialised_food_stores_small_businesses',
        'specialist_food_stores',
        'alcoholic_drinks_other_beverages_and_tobacco',
        'predominantly_non_food_stores_total_all_businesses',
        'predominantly_non_food_stores_total_large_businesses',
        'predominantly_non_food_stores_total_small_businesses',
        'non_specialised_non_food_stores_all_businesses',
        'non_specialised_non_food_stores_large_businesses',
        'non_specialised_non_food_stores_small_businesses',
        'textile_clothing_footwear_and_leather_all_businesses',
        'textile_clothing_footwear_and_leather_large_businesses',
        'textile_clothing_footwear_and_leather_small_businesses',
        'textiles',
        'clothing_all_businesses',
        'clothing_large_businesses',
        'clothing_small_businesses',
        'footwear_and_leather_goods',
        'household_goods_stores_all_businesses',
        'household_goods_stores_large_businesses',
        'household_goods_stores_small_businesses',
        'furniture_lighting_etc',
        'electrical_household_appliances',
        'hardware_paints_and_glass',
        'audio_and_video_and_music',
        'other_non_food_stores_all_businesses',
        'other_non_food_stores_large_businesses',
        'other_non_food_stores_small_businesses',
        'pharmaceutical_medical_cosmetic_and_toilet_goods',
        'books_newspapers_and_periodicals',
        'floor_coverings',
        'computers_and_telecomms_equipment',
        'specialised_stores_nec',
        'non_store_retail_all_retailing',
        'non_store_retail_large_businesses',
        'non_store_retail_small_businesses',
        'non_store_retail_mail_order_houses',
        'non_store_retail_excluding_mail_order',
        'automotive_fuel'
    ];
}