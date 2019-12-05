<?php
/**
 * This Class models the IpAddress model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * IpAddress Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class IpAddress extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'ip_address';

    protected $fillable = [
        'ip_address',
        'comment'
    ];
}