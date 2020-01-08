<?php
/**
 * This Class models the Zone model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Zone Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Zone extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'zone';

    protected $fillable = [
        'venue_id',
        'name',
        'capture_start',
        'wifi_zone',
        'tracking_zone',
        'category_id',
        'lat',
        'lon'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

     /**
     * return drones that belong to this zone
     */
    public function drones()
    {
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\Drone');
    }

    /**
     * return array of id's for the drones that belong to this zone
     */
    public function getDroneIds()
    {
        /**
         * get all drones for this zone and return an array containing the id's
         */
        $drones = Drone::where('zone_id', $this->id)->get();
        $drone_ids = array();
        foreach ($drones as $drone){
            $drone_ids[] = $drone['id'];
        }

        return $drone_ids;
    }

    /**
     * return access_points that belong to this zone
     */
    public function access_points()
    {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\AccessPoint');
    }

    /**
     * return array of id's for the access_points that belong to this zone
     */
    public function getAccessPointsIds()
    {
        /**
         * get all access_points for this zone and return an array containing the id's
         */
        $result = AccessPoint::where("zone_id", $this->id)->get();
        $access_point_ids = array();
        foreach ($result as $access_point){
            $access_point_ids[] = $access_point['id'];
        }

        return $access_point_ids;
    }

    /**
     * return venue this zone belongs to
     */
    public function venue()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * return category a zone belongs to
     */
    public function category()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Category');
    }

    /*******************************************************
     * TAG RELATED STUFF
     *******************************************************/

    /**
     * @var int[] An array of tags which belong to this zone. An empty array means that the tags have not been loaded yet.
     */
    protected $_tags;

    /**
     * Return a collection containing all tags this zone belongs to
     * @return array[] An array of Tag objects, indexed by the tag id.
     */
    public function tags()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag', 'zone_tag');
    }

    /**
     * Get a collection containing all tags that this Zone belongs to
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
     * Get an array of ids of tags to which this Zone belongs, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of ids of tags to which this Zone belongs
     */
    public function getTagIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_tags))
        {
            $result = Capsule::table('zone_tag')->select("tag_id")->where("zone_id", $this->id)->get();

            $this->_tags = [];
            foreach ($result as $tag){
                $this->_tags[] = $tag->tag_id;
            }
        }
        return $this->_tags;
    }

    public function delete()
    {
        // Remove all tag associations
        $this->tags()->detach();

        $this->access_points()->delete();
        $this->drones()->delete();

        // Delete the event itself
        $result = parent::delete();

        return $result;
    }

}