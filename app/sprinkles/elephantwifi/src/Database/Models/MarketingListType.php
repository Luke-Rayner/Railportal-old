<?php
/**
 * This Class models the MarketingListType model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * MarketingListType Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class MarketingListType extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'marketing_list_type';

    protected $fillable = [
        'name',
        'text'
    ];

    /**
     * Get the venue the marketing_list belongs to.
     */
    public function marketing_list() {
        return $this->hasMany('UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList');
    }
}