<?php
/**
 * This Class models the EventCategory model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * EventCategory Class
 *
 * @package Intelli-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class EventCategory extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'event_category';

    protected $fillable = [
        'name',
        'admin_category',
        'category_color'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;
}