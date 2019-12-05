<?php
/**
 * This Class models the VenueWifi model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VenueWifi Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class VenueWifi extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue_wifi';

    protected $fillable = [
        'venue_id',
        'capture_start',
        'controller_id',
        'controller_venue_id',
        'local_venue_id',
        'old_venue',
        'web_titan_id',
        'captive_portal',
        'is_sponsored',
        'mail_type',
        'marketing_public_key',
        'marketing_private_key',
        'old_unique_total',
        'old_marketing_total'
    ];

    /**
     * Get the venue this venue_tracking belongs to.
     */
    public function venue() {
        return $this->belongsTo('UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue');
    }

    /**
     * returns the UniFi controller this venue is hosted on
     */
    public function controller(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\Controller');
    }

    /**
     * returns the single venue_captive_portal_text_labels row that belongs to this venue
     */
    public function text_labels(){
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueTextLabels');
    }

    /**
     * returns the single venue_free_access_settings row that belongs to this venue
     */
    public function free_access_settings(){
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueFreeAccessSettings');
    }

    /**
     * returns the single venue_captive_portal_custom_css row that belongs to this venue
     */
    public function custom_css(){
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueCustomCSS');
    }    

    /**
     * Store the Venue to the database, along with what else we need to do depending
     * on our action (update/create)
     */
    public function save(array $options = []){
        /**
         * check whether we are creating a new Venue object or updating an existing one
         */
        if(isset($this->id)) {
            /**
             * Update the Venue record itself
             */
            $result = parent::save($options);
        } else {
            /**
             * Update the Venue record itself before we can start attaching children
             */
            $result = parent::save($options);

            /**
             * here we need to create these child objects following this logic:
             *
             *   create new object: text_labels from en_US template object or from nl_NL template object
             *   create new object from template object: custom_css
             *   create new object from template object: free_access_settings
             *
             * we start with the text labels
             */
            $new_text_labels_template = VenueTextLabels::where('is_template', 1)
                ->where('locale', 'en_US') //TODO: locale needs to be dynamic
                ->first()
                ->replicate();

            /**
             * modify, then attach the modified text_labels template
             */
            $new_text_labels_template->is_template = false;
            $attach_text_template = $this->text_labels()->save($new_text_labels_template);

            /**
             * custom CSS now
             */
            $new_custom_css_template = VenueCustomCSS::where('is_template', 1)
                                                    ->first()
                                                    ->replicate();

            /**
             * modify, then attach the modified custom_css template
             */
            $new_custom_css_template->is_template = false;
            $attach_css_template = $this->custom_css()->save($new_custom_css_template);

            /**
             * custom CSS now
             */
            $new_settings_template = VenueFreeAccessSettings::where('is_template', 1)
                                                           ->first()
                                                           ->replicate();

            /**
             * modify, then attach the modified free_access_settings template
             */
            $new_settings_template->is_template = false;
            $new_settings_template->active = true;
            $attach_css_template = $this->free_access_settings()->save($new_settings_template);
        }

        return $result;
    }

    /**
     * what needs to be done when we call delete() on a Venue object
     */
    public function delete(){
        /**
         * also delete these Venue relationships and the child objects themselves:
         * - text_labels()
         * - free_access_settings()
         * - custom_css()
         */
        $this->text_labels()->delete();
        $this->free_access_settings()->delete();
        $this->custom_css()->delete();

        /**
         * Delete the venue itself
         */
        $result = parent::delete();

        return $result;
    }
}