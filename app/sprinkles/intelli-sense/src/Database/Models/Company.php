<?php
/**
 * This Class models the Company model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Company Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Company extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'company';

    protected $fillable = [
        'name'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    public function users(){
        return $this->hasMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\User');
    }
}