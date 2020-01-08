<?php
/**
 * This Class models the Region model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Region Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Region extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'region';

    protected $fillable = [
        'country_id',
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return venues with this region
     */
    public function venue(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * return country this region belongs to
     */
    public function country(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Country');
    }

    /**
     * return areas which belong to this region
     */
    public function areas(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Area');
    }

    public function delete(){
        // Delete the region itself
        $result = parent::delete();
        return $result;
    }
}