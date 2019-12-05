<?php
/**
 * This Class models the Tag model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Tag Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Tag extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'tag';

    protected $fillable = [
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return zones with this tag
     */
    public function zones(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone', 'zone_tag');
    }

    /**
     * return venues with this tag
     */
    public function venues(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'venue_tag');
    }

    public function delete(){
        // Remove all zone associations from the pivot table
        $this->zones()->detach();

        // Remove all venue associations from the pivot table
        $this->venues()->detach();

        // Delete the tag itself
        $result = parent::delete();
        return $result;
    }
}