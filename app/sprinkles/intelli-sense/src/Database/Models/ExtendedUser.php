<?php
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use UserFrosting\Sprinkle\Account\Database\Models\User;
use UserFrosting\Sprinkle\Account\Database\Models\Role;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUserAux;
use UserFrosting\Sprinkle\IntelliSense\Database\Scopes\ExtendedUserAuxScope;
use \Illuminate\Database\Capsule\Manager as Capsule;

trait LinkExtendedUserAux
{
    /**
     * The "booting" method of the trait.
     *
     * @return void
     */
    protected static function bootLinkExtendedUserAux()
    {
        /**
         * Create a new ExtendedUserAux if necessary, and save the associated extended_user data every time.
         */
        static::saved(function ($extended_user) {
            $extended_user->createAuxIfNotExists();
            if ($extended_user->auxType) {
                // Set the aux PK, if it hasn't been set yet
                if (!$extended_user->aux->id) {
                    $extended_user->aux->id = $extended_user->id;
                }
                $extended_user->aux->save();
            }
        });
    }
}

class ExtendedUser extends User
{
    use LinkExtendedUserAux;

    protected $fillable = [
        'user_name',
        'first_name',
        'last_name',
        'email',
        'locale',
        'theme',
        'group_id',        
        'flag_verified',
        'flag_enabled',
        'last_activity_id',
        'password',
        'deleted_at',
        'company_id',
        'primary_venue_id',
        'full_venue_view_allowed',
        'session_expiry_time'
    ];

    protected $auxType = 'UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUserAux';

    /**
     * @var int[] An array of venue_ids to which this user has access to. An empty array means that the user's venues have not been loaded yet.
     */
    protected $_venues;

    /**
     * @var int[] An array of zone_ids to which this user has access to. An empty array means that the user's zones have not been loaded yet.
     */
    protected $_zones;

    protected $_roles;

    protected $_wifiUserVenues;

    /**
     * Required to be able to access the `aux` relationship in Twig without needing to do eager loading.
     * @see http://stackoverflow.com/questions/29514081/cannot-access-eloquent-attributes-on-twig/35908957#35908957
     */
    public function __isset($name)
    {
        if (in_array($name, [
            'aux',
            'venue_name',
            'company_name'
        ])) {
            return true;
        } else {
            return parent::__isset($name);
        }
    }

    /**
     * Get a property for this object.
     *
     * @param string $name the name of the property to retrieve.
     * @throws Exception the property does not exist for this object.
     * @return string the associated property.
     */
    public function __get($name){
        if ($name == "venue_name")
            return $this->primaryVenue->name;
        else if ($name == "api_key")
            return $this->getApiKey;
        else if ($name == "company_name")
            return $this->company->name;
        else
            return parent::__get($name);
    }

    /**
     * Globally joins the `extended_users` table to access additional properties.
     */
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new ExtendedUserAuxScope);
    }

    /**
     * Relationship for interacting with aux model (`extended_users` table).
     */
    public function aux()
    {
        return $this->hasOne($this->auxType, 'id');
    }

    /**
     * If this instance doesn't already have a related aux model (either in the db on in the current object), then create one
     */
    protected function createAuxIfNotExists()
    {
        if ($this->auxType && !count($this->aux)) {
            // Create aux model and set primary key to be the same as the main user's
            $aux = new $this->auxType;
            // Needed to immediately hydrate the relation.  It will actually get saved in the bootLinkExtendedUserAux method.
            $this->setRelation('aux', $aux);
        }
    }

    /**
     * Joins the user's company, so we can do things like sort, search, paginate, etc.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeJoinCompany($query)
    {
        $query = $query->select('company.*');

        $query = $query->leftJoin('company', 'company.id', '=', 'extended_users.company_id');

        return $query;
    }

    /**
     * Get the primary venue for this this user
     */
    public function venue()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'primary_venue_id');
    }

    /**
     * returns the Api Key that belongs to this user
     */
    public function api_key()
    {
        return $this->hasOne('UserFrosting\Sprinkle\GeoSense\Database\Models\ApiKey', 'user_id');
    }

    /**
     * Get the API key object for the current user
     */
    public function getApiKey()
    {
        // Fetch from database, if not set
        if (!isset($this->_api_key)){
            return $this->hasOne('UserFrosting\Sprinkle\GeoSense\Database\Models\ApiKey', 'user_id');
        }
        return $this->_api_key;
    }

    /**
     * Get the company this user belongs to.
     */
    public function company()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Company');
    }

    public function identities()
    {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity');
    }

    /**
     * Get the primary venue this user belongs to, and eager load the controller details as well.
     */
    public function primaryVenue()
    {
        // return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'primary_venue_id')->with('zones.drones', 'venue_wifi', 'venue_tracking', 'enviro_sensors');
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'primary_venue_id')->with('zones.drones', 'venue_tracking');
    }

    /**
     * Return an array containing all venues this user has access to
     * @return Venue[] An array of Venue objects, indexed by the venue id.
     */
    public function venues()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'venue_user', 'user_id', 'venue_id')->withTimestamps();
    }

    /**
     * Get an array containing all venues this user has access to
     * @return Group[] An array of Venue objects, indexed by the venue id.
     *
     * TODO:
     * return array of objects containing venueid and venue name, ordered by venue name
     */
     public function getVenues()
     {
        $this->getVenueIds();

        // Return the array of venue objects
        $result = Venue::find($this->_venues);
        // $result = Venue::with('zones')->find($this->_venues);

        $venues = [];
        foreach ($result as $venue){
            $venues[$venue->id] = $venue;
        }

        // Then sort the venues by name
        $venue_collection = collect($venues);
        $venues_sorted = $venue_collection->sortBy('name');
        return $venues_sorted;
    }

    /**
     * Get an array of venue_ids to which this User currently has access, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of venue_ids to which this User has access
     */
    public function getVenueIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_venues)){
            $result = Capsule::table('venue_user')->select("venue_id")->where("user_id", $this->id)->get();

            $this->_venues = [];
            foreach ($result as $venue){
                $this->_venues[] = $venue->venue_id;
            }
        }
        return $this->_venues;
    }

    /**
     * Return an array containing all venues this user has access to
     * @return Venue[] An array of Venue objects, indexed by the venue id.
     */
    public function wifiUserVenues()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue', 'venue_wifi_user', 'user_id')->withTimestamps();
    }

    /**
     * Get an array containing all venues this user has access to
     * @return Group[] An array of Venue objects, indexed by the venue id.
     *
     * TODO:
     * return array of objects containing venueid and venue name, ordered by venue name
     */
     public function getWiFiUserVenues()
     {
        $this->getWifiUserVenueIds();

        // Return the array of venue objects
        $result = Venue::find($this->_wifiUserVenues);
        // $result = Venue::with('zones')->find($this->_venues);

        $venues = [];
        foreach ($result as $venue){
            $venues[$venue->id] = $venue;
        }

        // Then sort the venues by name
        $venue_collection = collect($venues);
        $venues_sorted = $venue_collection->sortBy('name');
        return $venues_sorted;
    }

    /**
     * Get an array of venue_ids to which this User currently has access, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of venue_ids to which this User has access
     */
    public function getWifiUserVenueIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_wifiUserVenues)){
            $result = Capsule::table('venue_wifi_user')->select("venue_id")->where("user_id", $this->id)->get();

            $this->_wifiUserVenues = [];
            foreach ($result as $venue){
                $this->_wifiUserVenues[] = $venue->venue_id;
            }
        }
        return $this->_wifiUserVenues;
    }

    /**
     * Return an array containing all zones this user has access to
     * @return array[] An array of Zone objects, indexed by the zone id.
     */
    public function zones()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone', 'zone_user', 'user_id', 'zone_id')
            ->withTimestamps();
    }

    /**
     * Get an array containing all zones this user has access to
     */
     public function getZones()
     {
        $this->getZoneIds();

        // Return the array of zone objects
        $result = Zone::find($this->_zones);

        $zones = [];
        foreach ($result as $zone){
            $zones[$zone->id] = $zone;
        }
        return $zones;
    }

    /**
     * Get an array of ids of zones to which this User currently has access, as currently represented in this object.
     *
     * This method does NOT modify the database.
     * @return array[int] An array of ids of zones to which this User has access
     */
    public function getZoneIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_zones)){
            $result = Capsule::table('zone_user')->select("zone_id")->where("user_id", $this->id)->get();

            $this->_zones = [];
            foreach ($result as $zone){
                $this->_zones[] = $zone->zone_id;
            }
        }
        return $this->_zones;
    }

    public function alerts()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\AlertNotification', 'user_alert_notification', 'user_id')->withTimestamps();
    }

    public function getRoleIds()
    {
        // Fetch from database, if not set
        if (!isset($this->_roles)){
            $result = Capsule::table('role_users')->select("role_id")->where("user_id", $this->id)->get();

            $this->_roles = [];
            foreach ($result as $role){
                $this->_roles[] = $role->role_id;
            }
        }

        return $this->_roles;
    }

    public function getRoles(){
        $this->getRoleIds();

        // Return the array of group objects
        $result = Role::find($this->_roles);

        $roles = [];
        foreach ($result as $role){
            $roles[$role->id] = $role;
        }
        return $roles;
    }

    public function addRole($role_id){
        $this->getRoleIds();

        // Return if user already in group
        if (in_array($role_id, $this->_roles))
            return $this;

        // Next, check that the requested group actually exists
        if (!Role::find($role_id))
            throw new \Exception("The specified role_id ($role_id) does not exist.");

        // Ok, add to the list of groups
        $this->_roles[] = $role_id;

        return $this;
    }

    public function removeRole($role_id){
        // Fetch from database, if not set
        $this->getRoleIds();

        // Check that user not in group
        if (($key = array_search($role_id, $this->_roles)) !== false) {
            unset($this->_roles[$key]);
        }

        return $this;
    }

    /**
     * Custom mutator for ExtendedUser property
     */
    public function setPrimaryVenueIdAttribute($value)
    {
        $this->createAuxIfNotExists();

        $this->aux->primary_venue_id = $value;
    }

    /**
     * Custom mutator for ExtendedUser property
     */
    public function setCompanyIdAttribute($value)
    {
        $this->createAuxIfNotExists();

        $this->aux->company_id = $value;
    }

    /**
     * Custom mutator for ExtendedUser property
     */
    public function setFullVenueViewAllowedAttribute($value)
    {
        $this->createAuxIfNotExists();

        $this->aux->full_venue_view_allowed = $value;
    }

    /**
     * Custom mutator for ExtendedUser property
     */
    public function setSessionExpiryTimeAttribute($value)
    {
        $this->createAuxIfNotExists();

        $this->aux->session_expiry_time = $value;
    }
}