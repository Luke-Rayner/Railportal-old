<?php
/**
 * This Class models the CalibrationDevice model following the calibration_device table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;

/**
 * CalibrationDevice Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class CalibrationDevice extends UserFrosting\Sprinkle\Core\Database\Models\Model 
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'calibration_device';

    protected $fillable = [
        'label',
        'mac'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;
}