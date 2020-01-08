<?php
/**
 * This Class models the Session model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Session Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class Session extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'session';

    protected $fillable = [
        'device_id',
        'browser',
        'limited_cna',
        'ap_mac_address',
        'php_session_id',
        'ssid',
        'orig_url',
        'venue_id',
        'identity_id',
        'auth_status'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model named is_template,
     * please return it, cast to type integer."
     */
    protected $casts = [
        'limited_cna' => 'integer',
        'auth_status' => 'integer'
    ];

    /**
     * All of the relationships to be touched.
     *
     * @var array
     */
    protected $touches = ['device', 'identity'];

    /*
    returns the venue this session was requested for
    */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * returns the device for this session
     */
    public function device(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Device');
    }

    /**
     * returns the identity for this session
     */
    public function identity(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity');
    }

    public function delete(){
        /**
         * Delete the session itself
         */
        $result = parent::delete();

        return $result;
    }

}