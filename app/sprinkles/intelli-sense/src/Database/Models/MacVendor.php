<?php
/**
 * This Class models the MacVendor model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * MacVendor Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class MacVendor extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'macvendor';

    protected $fillable = [
        'macaddr',
        'vendor',
        'description'
    ];
}