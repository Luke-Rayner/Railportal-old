<?php
/**
 * This Class models the Device model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Device Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class Device extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'device';

    protected $fillable = [
        'venue_id',
        'mac_address',
        'device_uuid',
        'os',
        'browser',
        'brand',
        'first_seen',
        'last_seen',
        'auth_expiry_date',
        'registration_expiry_date',
        'authorised_visits',
        'unauthorised_visits',
        'reshow_terms',
        'accepted_terms',
        'reshow_marketing'
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
        'first_seen' => 'integer',
        'last_seen' => 'integer',
        'auth_expiry_date' => 'integer',
        'registration_expiry_date' => 'integer'
    ];

    /**
     * Hides device_uuid from json but not query
     */
    protected $hidden = array('device_uuid');

    /**
     * Get a property for this object.
     *
     * @param string $name the name of the property to retrieve.
     * @throws Exception the property does not exist for this object.
     * @return string the associated property.
     */
    public function __get($name){
        if ($name == "device_uuid")
            return $this->getDeviceUUID();
        else
            return parent::__get($name);
    }

    public function getDeviceUUID(){
        return hex2bin(md5($this->mac_address . $this->venue_id));
    }

    /**
     * returns the venue that this device is related to
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * returns the sessions related to this device
     */
    public function sessions(){
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Session');
    }

    public function delete(){
        /**
         * Delete the device itself
         */
        $result = parent::delete();

        return $result;
    }
}