<?php
/**
 * This Class models the drone model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Drone Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Drone extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'drone';

    protected $fillable = [
        'state',
        'serial',
        'local_ip',
        'software_version',
        'drone_revision_code_id',
        'mac_address',
        'api_version',
        'name',
        'zone_id',
        'lat',
        'lon',
        'execute_command',
        'execute_command_delay',
        'rssi_threshold',
        'delay_period',
        'drone_summary',
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Get all probe requests for this drone.
     */
    public function probe_requests(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\ProbeRequest');
    }

    /**
     * Get revision code for this drone
     */
    public function drone_revision_code(){
        return $this->belongsTo('UserFrosting\Sprinkle\GeoSense\Database\Models\RevisionCode');
    }

    /**
     * Get the most recent stored probe request for this drone.
     */
    public function lastProbeRequest() {
        return $this->probe_requests()
                    ->orderBy('ts', 'desc')
                    ->first();
    }

    /**
     * Get timestamp of the latest probe request submitted (not necessarily stored) by this drone
     */
    public function last_activity(){
        return $this->hasOne('UserFrosting\Sprinkle\GeoSense\Database\Models\DroneLatestCapture');
    }

    /**
     * Get all health messages for this drone.
     */
    public function health_messages(){
        return $this->hasMany('UserFrosting\Sprinkle\GeoSense\Database\Models\DroneHealth');
    }

    /**
     * Get the most recent health message for this drone.
     */
    public function lastHealthMessage() {
        return $this->health_messages()
                    ->orderBy('timestamp', 'desc')
                    ->first();
    }

    /**
     * Get the zone this drone belongs to.
     */
    public function zone(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone');
    }

    /**
     * method to insert a new drone: not yet implemented
     */
    public function insert(){
       // Access-controlled resource
       if (!$this->_app->user->checkAccess('uri_drone_insert')){
           $this->_app->notFound();
       }
       // do something here
    }

    public function delete(){
        // Remove all user associations
        $this->probe_requests()->detach();

        // Delete the event itself
        $result = parent::delete();

        return $result;
    }
}