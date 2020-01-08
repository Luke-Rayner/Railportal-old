<?php
/**
 * This Class models the SubCategory model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * SubCategory Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class SubCategory extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'sub_category';

    protected $fillable = [
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return venues with this sub_category
     */
    public function venues(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'venue_sub_category');
    }

    public function delete(){
        // Remove all venue associations from the pivot table
        $this->venues()->detach();

        // Delete the sub_category itself
        $result = parent::delete();
        return $result;
    }
}