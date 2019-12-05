<?php
/**
 * This Class models the MacPrefix model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model ;

/**
 * MacPrefix Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class MacPrefix extends Model 
{
    protected static $_table_id = 'mac_prefix';
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'mac_prefix';

    protected $fillable = [
        'prefix',
        'device_vendor_id'
    ];

    /**
     * Get the device_vendor this drone belongs to.
     */
    public function device_vendor(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor');
    }
}