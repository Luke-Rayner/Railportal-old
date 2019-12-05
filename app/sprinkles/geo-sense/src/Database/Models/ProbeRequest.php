<?php
/**
 * This Class models the DeviceVendor model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * ProbeRequest Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class ProbeRequest extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'probe_request';

    protected $fillable = [
        'ts',
        'device_uuid',
        'device_vendor_id',
        'rssi',
        'drone_id',
        'venue_id'
    ];

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

    public function drone(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\Drone');
    }

    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * Get the device_vendor a probe_request belongs to.
     */
    public function device_vendor(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}