<?php
/**
 * This Class models the Category model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Category Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Category extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'category';

    protected $fillable = [
        'name'
    ];

    /**S
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /*
    return zones with this category
    */
    public function zones(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }

    /*
    return venues with this category
    */
    public function venues(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    public function delete(){
        // Delete the category itself
        $result = parent::delete();
        return $result;
    }
}