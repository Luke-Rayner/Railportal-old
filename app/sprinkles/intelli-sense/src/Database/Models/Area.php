<?php
/**
 * This Class models the Area model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Area Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Area extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'area';

    protected $fillable = [
        'region_id',
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return venues with this area
     */
    public function venue(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * return region this area belongs to
     */
    public function region(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Region');
    }

    public function delete(){
        // Delete the area itself
        $result = parent::delete();
        return $result;
    }
}