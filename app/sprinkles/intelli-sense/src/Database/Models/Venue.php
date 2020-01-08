<?php
/**
 * This Class models the Venue model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Venue Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Venue extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue';

    protected $fillable = [
        'name',
        'wifi_venue',
        'tracking_venue',
        'enviro_venue',
        'category_id',
        'lat',
        'lon',
        'country_id',
        'region_id',
        'area_id',
        'population',
        'time_zone',
        'locale',
        'footfall_bucket',
        'current_visitors_bucket',
        'dashboard_refresh',
        'heatmap_min_zoom',
        'heatmap_max_zoom',
        'heatmap_init_zoom',
        'heatmap_radius',
        'show_stats_on_login',
        'dt_threshold_1',
        'dt_threshold_2',
        'dt_threshold_3',
        'dt_threshold_4',
        'dt_threshold_5',
        'dt_level_1_label',
        'dt_level_2_label',
        'dt_level_3_label',
        'dt_level_4_label',
        'dt_level_5_label',
        'dt_skipped_label',
        'sankey_max_route_length'
    ];

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model named is_sponsored,
     * please return it, cast to type integer."
     */
    protected $casts = [
        'is_sponsored' => 'integer'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Get a property for this object.
     *
     * @param string $name the name of the property to retrieve.
     * @throws Exception the property does not exist for this object.
     * @return string the associated property.
     */
    public function __get($name){
        if ($name == 'thezones')
            return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
        else
            return parent::__get($name);
    }

    /**
     * returns all zones for active venue
     */
    public function zones(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }

    /**
     * return venue_tracking info
     */
    public function venue_tracking(){
        return $this->hasOne('UserFrosting\Sprinkle\GeoSense\Database\Models\VenueTracking');
    }

    /**
     * return venue_wifi info
     */
    public function venue_wifi(){
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi');
    }

    /**
     * returns all wifi_client_connections for active venue
     */
    public function wifi_clients_connections(){
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiClientConnection');
    }

    /**
     * returns the UniFi controller this venue is hosted on
     */
    // public function controller(){
    //     return $this->belongsTo('UserFrosting\Controller');
    // }

    /**
     * returns all users that have access to this venue
     *
     * Many to Many requires this type of relationship (belongsToMany) on both ends
     */
    public function users(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser', 'venue_user');
    }    

    /**
     * return category a venue belongs to
     */
    public function category(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Category');
    }

    /**
     * Get the Country for this site as a dynamic property
     */
    public function getCountryAttribute(){
        return Country::where('id', $this->country_id)->first();
    }

    /**
     * returns all probe requests for active venue
     */
    public function probe_requests(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\ProbeRequest');
    }

    /**
     * returns all daily visitor stats for the current venue
     */
    public function tracking_daily_stats_visitors(){
        if (isset($this->capture_start)) {
            return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueVisitors')
                        ->where('day_epoch', '>=', $this->capture_start);
        } else {
            return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueVisitors');
        }
    }

    /**
     * returns all daily visitor stats for the current venue
     */
    public function wifi_daily_stats_visitors(){
        if (isset($this->capture_start)) {
            return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueVisitors')
                        ->where('day_epoch', '>=', $this->capture_start);
        } else {
            return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueVisitors');
        }
    }

    /**
     * returns all enviro_sensor entries for active venue
     */
    public function enviro_sensors(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor');
    } 

    /**
     * returns all sensor daqi data for this venue
     */
    public function sensor_daqi_data(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\SensorDailyDaqiData');
    }

    /**
     * returns all whitelist entries for active venue
     */
    public function whitelist(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\Whitelist');
    }   

    /**
     * Get the associated identities for this venue
     */
    public function identities()
    {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity');
    }

    /**
     * Get the associated marketing lists for this venue
     */
    public function marketing_lists()
    {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList');
    }

    /**
     * return country a venue belongs to
     */
    public function country(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Country');
    }

    /**
     * return region a venue belongs to
     */
    public function region(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Region');
    }

    /**
     * return area a venue belongs to
     */
    public function area(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Area');
    }

    /*******************************************************
     * TAG RELATED STUFF
     *******************************************************/

    /**
     * @var int[] An array of tag_ids which belong to this venue. An empty array means that the tags have not been loaded yet.
     */
    protected $_tags;

    /**
     * Return a collection containing all tags this venue belongs to
     * @return array[] An array of Tag objects, indexed by the tag id.
     */
    public function tags()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag', 'venue_tag');
    }

    /**
     * Get the tags for this venue as a dynamic property
     */
    public function getTagsAttribute()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag', 'venue_tag');
    }

    /**
     * Get a collection containing all tags that this venue belongs to
     */
     public function getTags()
     {
        $this->getTagIds();

        // Return the collection of tag objects
        $result = Tag::find($this->_tags);

        $tags = [];
        foreach ($result as $tag){
            $tags[$tag->id] = $tag;
        }
        return $tags;
    }

    /**
     * Get an array of ids of tags which are associated with this Venue, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of ids of tags to which this Venue belongs
     */
    public function getTagIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_tags)){
            $result = Capsule::table('venue_tag')->select('tag_id')->where('venue_id', $this->id)->get();

            $this->_tags = [];
            foreach ($result as $tag){
                $this->_tags[] = $tag->tag_id;
            }
        }
        return $this->_tags;
    }

    /*******************************************************
     * SUB-CATEGORY RELATED STUFF
     *******************************************************/

    /**
     * @var int[] An array of sub_category_ids which belong to this venue. An empty array means that the sub_categories have not been loaded yet.
     */
    protected $_sub_categories;

    /**
     * Return a collection containing all sub_categories this venue belongs to
     * @return array[] An array of sub_category objects, indexed by the sub_category id.
     */
    public function sub_categories()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\SubCategory', 'venue_sub_category');
    }

    /**
     * Get the sub_categories for this venue as a dynamic property
     */
    public function getSubCategoriesAttribute()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\SubCategory', 'venue_sub_category');
    }

    /**
     * Get a collection containing all sub_categories that this venue belongs to
     */
     public function getSubCategories()
     {
        $this->getSubCategoryIds();

        // Return the collection of sub_category objects
        $result = SubCategory::find($this->_sub_categories);

        $sub_categories = [];
        foreach ($result as $sub_category){
            $sub_categories[$sub_category->id] = $sub_category;
        }
        return $sub_categories;
    }

    /**
     * Get an array of ids of sub_categories which are associated with this Venue, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of ids of sub_categories to which this Venue belongs
     */
    public function getSubCategoryIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_sub_categories)){
            $result = Capsule::table('venue_sub_category')->select('sub_category_id')->where('venue_id', $this->id)->get();

            $this->_sub_categories = [];
            foreach ($result as $sub_category){
                $this->_sub_categories[] = $sub_category->sub_category_id;
            }
        }
        return $this->_sub_categories;
    }

    /**
     * what needs to be done when we call delete() on a Venue object
     */
    public function delete()
    {
        // Remove all user associations, but do not delete the User objects themselves
        $this->users()->detach();

        // Remove all zone associations
        $this->zones()->delete();

        // Remove all tag associations
        $this->tags()->detach();

        // Remove all sub_category associations
        $this->sub_categories()->detach();

        /**
         * Delete the venue itself
         */
        $result = parent::delete();

        return $result;
    }
}