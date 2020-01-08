<?php
/**
 * This Class models the ApiKey model following the table definition
 */
namespace UserFrosting\Sprinkle\GeoSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * ApiKey Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class ApiKey extends Model
{
	/**
     * @var string The name of the table for the current model.
     */
    protected $table = 'api_key';

    protected $fillable = [
        'issued_at',
        'expires_at',
        'user_id',
        'value'
    ];

    /**
     * @var bool Enable timestamps for Users.
     */
    public $timestamps = true;

    public function user(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\User');
    }
}