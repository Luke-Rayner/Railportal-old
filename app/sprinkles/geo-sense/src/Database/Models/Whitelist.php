<?php
/**
 * This Class models the Whitelist model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Whitelist Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Whitelist extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'whitelist';

    protected $fillable = [
        'venue_id',
        'label',
        'mac',
        'device_vendor_id',
        'device_uuid',
        'whitelist'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * accessor/mutator for the device_uuid attribute
     * https://laravel.com/docs/5.5/eloquent-mutators#accessors-and-mutators
     */
    public function setDeviceUuidAttribute($value){
        $this->attributes['device_uuid'] = hex2bin($value);
    }

    public function getDeviceUuidAttribute($value){
        return bin2hex($value);
    }

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * Get the device_vendor a whitelist entry belongs to.
     */
    public function device_vendor(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}