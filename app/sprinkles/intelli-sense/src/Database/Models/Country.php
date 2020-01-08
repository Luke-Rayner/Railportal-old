<?php
/**
 * This Class models the Country model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Country Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Country extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'country';

    protected $fillable = [
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return venues with this country
     */
    public function venue(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * return regions which belong to this country
     */
    public function regions(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Region');
    }

    public function delete(){
        // Delete the country itself
        $result = parent::delete();
        return $result;
    }
}