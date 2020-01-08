<?php
/**
 * This Class models the SiteConfiguration model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * SiteConfiguration Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class SiteConfiguration extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'site_configuration';

    protected $fillable = [
        'plugin',
        'name',
        'value',
        'form_label',
        'description'
    ];
}