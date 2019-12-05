<?php
/**
 * This Class models the EnviroSensorModuleType model following the table definition
 */
namespace UserFrosting\Sprinkle\EnviroSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EnviroSensorModuleType Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
class EnviroSensorModuleType extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'enviro_sensor_module_type';

    protected $fillable = [
        "name",
        "mass",
        "tiers"
    ];

    /**
     * @var bool Enable timestamps for Sensors.
     */
    public $timestamps = true;

    /**
     * Get all the enviro sensor modules for this module type belongs to
     */
    public function enviro_sensor_modules(){
        return $this->hasMany('UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModule');
    }
}