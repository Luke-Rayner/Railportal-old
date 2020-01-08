<?php
/**
 * This Class models the ApConfig model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * ApConfig Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class ApConfig extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'ap_config';

    protected $fillable = [
        'access_point_idIndex',
        'name',
        'firmware_version',
        'ip_address',
        'uptime'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Get the ap this ap_config belongs to
     */
    public function access_point(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\AccessPoint');
    }

    /**
     * Get all the ap_radios for this ap.
     */
    public function ap_radios(){
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\ApRadio');
    }
}