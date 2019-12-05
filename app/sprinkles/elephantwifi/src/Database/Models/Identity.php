<?php
/**
 * This Class models the Identity model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * Identity Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class Identity extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'identity';

    protected $fillable = [
        'venue_id',
        'user_id',
        'first_name',
        'last_name',
        'email_address',
        'authenticated_by',
        'provider',
        'profile_id',
        'gender',
        'birth_date',
        'postcode',
        'county',
        'city',
        'hometown',
        'avatar_url'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model named birth_date,
     * please return it, cast to type integer."
     */
    protected $casts = [
        'birth_date' => 'integer'
    ];

    /**
     * returns the venue this session was requested for
     */
    public function venue()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * returns the sessions related to this identity
     */
    public function sessions()
    {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Session');
    }

    public function user()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser');
    }

    /**
     * Return an array containing all venues this user has access to
     * @return Venue[] An array of Venue objects, indexed by the venue id.
     */
    public function marketing_lists() 
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList', 'identity_list')->withTimestamps();
    }

    /**
     * TODO: add link to venue here? Relationship goes through the session
     */

    public function delete(){
        // Delete the event itself
        $result = parent::delete();

        return $result;
    }

}