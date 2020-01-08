<?php
/**
 * This Class models the SocialKeys model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * SocialKeys Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class SocialKeys extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'social_keys';

    protected $fillable = [
        'provider',
        'app_id',
        'public_key',
        'secret_key'
    ];
}