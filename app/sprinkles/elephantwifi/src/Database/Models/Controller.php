<?php
/**
 * This Class models the Controller model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Controller Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class Controller extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'controller';

    protected $fillable = [
        'name',
        'url',
        'shared',
        'user_name',
        'password',
        'contact',
        'version',
        'version_last_check'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model named first_seen,
     * please return it, cast to type integer."
     */
    protected $casts = [
        'shared'             => 'integer',
        'version_last_check' => 'integer'
    ];

    public function wifi_venues(){
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi');
    }

    public function delete(){
        // Delete the event itself
        $result = parent::delete();

        return $result;
    }
}