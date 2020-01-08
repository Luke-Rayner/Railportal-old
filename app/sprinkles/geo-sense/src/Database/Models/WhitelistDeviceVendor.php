<?php
/**
 * This Class models the WhitelistDeviceVendor model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * WhitelistDeviceVendor Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class WhitelistDeviceVendor extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'whitelist_device_vendor';

    protected $fillable = [
        'device_vendor_id'
    ];

    /**
     * return mac_prefixes that belong to this device_vendor
     */
    public function device_vendor(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}