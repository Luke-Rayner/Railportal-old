<?php
/**
 * This Class models the MarketingList model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * MarketingList Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class MarketingList extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'marketing_list';

    protected $fillable = [
        'venue_id',
        'marketing_list_type_id',
        'mail_type',
        'list_uid',
        'list_name',
        'list_description',
        'from_name',
        'from_email',
        'reply_to',
        'subject',
        'company_name',
        'company_country',
        'company_county',
        'company_address_1',
        'company_address_2',
        'company_city',
        'company_postcode',
    ];

    /**
     * Get the venue the marketing_list belongs to.
     */
    public function venue()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * Get the marketing_list_type for this list.
     */
    public function marketing_list_type()
    {
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingListType');
    }

    /**
     * returns all users that have access to this venue
     *
     * Many to Many requires this type of relationship (belongsToMany) on both ends
     */
    public function identities()
    {
        return $this->belongsToMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity', 'identity_list');
    }
}