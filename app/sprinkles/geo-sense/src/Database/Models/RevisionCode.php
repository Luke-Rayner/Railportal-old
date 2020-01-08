<?php
/**
 * This Class models the RevisionCode model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

use \Illuminate\Database\Capsule\Manager as Capsule;

/**
 * RevisionCode Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class RevisionCode extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'drone_revision_code';

    protected $fillable = [
        'revision',
        'model'
    ];

    public function drones(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\Drone');
    }
}