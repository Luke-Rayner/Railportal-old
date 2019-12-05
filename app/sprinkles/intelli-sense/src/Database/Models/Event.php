<?php
/**
 * This Class models the Event model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Event Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class Event extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'event';

    protected $fillable = [
        'name',
        'notes',
        'venue_id',
        'event_category_id',
        'start_date',
        'end_date',
        'recurring',
        'admin_event',
        'admin_notes',
        'can_delete'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * return venue this event belongs to
     */
    public function venue(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * return category this event belongs to
     */
    public function event_category(){
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\EventCategory');
    }
}