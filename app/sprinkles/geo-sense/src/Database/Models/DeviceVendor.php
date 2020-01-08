<?php
/**
 * This Class models the DeviceVendor model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * DeviceVendor Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class DeviceVendor extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'device_vendor';

    protected $fillable = [
        'name',
        'description'
    ];

    /**
     * return mac_prefixes that belong to this device_vendor
     */
    public function mac_prefixes(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\MacPrefix');
    }
}