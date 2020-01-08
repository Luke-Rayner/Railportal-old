<?php
/**
 * This Class models the AlertNotification model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * AlertNotification Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 */
class AlertNotification extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'alert_notification';

    protected $fillable = [
        'status',
        'title',
        'link',
        'file_uuid',
        'file_name',
        'set_date'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Return an array containing all venues this alert is linked to
     * @return Venue[] An array of Venue objects, indexed by the venue id.
     */
    public function venues(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'venue_alert_notification')->withTimestamps();
    }

    public function users(){
        return $this->belongsToMany('UserFrosting\Sprinkle\Account\Database\Models\User', 'user_alert_notification')->withTimestamps();
    }
}