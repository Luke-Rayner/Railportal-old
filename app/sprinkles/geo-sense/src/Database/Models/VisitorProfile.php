<?php
/**
 * This Class models the VisitorProfile model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VisitorProfile Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class VisitorProfile extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'visitor_profile';

    protected $fillable = [
        'device_uuid',
        'gender',
        'age',
        'postcode'
    ];
}