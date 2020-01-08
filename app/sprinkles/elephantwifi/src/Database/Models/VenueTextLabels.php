<?php
/**
 * This Class models the VenueTextLabels model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VenueTextLabels Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class VenueTextLabels extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue_captive_portal_text_labels';

    protected $fillable = [
        'is_template',
        'locale',
        'venue_wifi_idIndex',
        'page_title',
        'heading',
        'sub_heading',
        'basic_sub_heading',
        'registration_sub_heading',
        'social_auth_sub_heading',
        'motd_sub_heading',
        'tos_title',
        'tos_pre_link_label',
        'tos_post_link_label',
        'tos_modal_content',
        'tos_modal_dismiss_button_label',
        'basic_connect_button_label',
        'basic_connect_button_class',
        'first_name_label',
        'last_name_label',
        'email_label',
        'gender_label',
        'birth_date_label',
        'postcode_label',
        'first_name_placeholder',
        'last_name_placeholder',
        'email_placeholder',
        'postcode_placeholder',
        'registration_form_button_label',
        'registration_form_button_class',
        'motd_form_button_label',
        'motd_form_button_class',
        'redirecting',
        'social_auth_button_label_pre_provider',
        'social_auth_button_label_registration',
        'social_auth_button_size',
        'social_auth_connecting_pre_provider',
        'social_auth_redirecting_pre_provider',
        'social_auth_redirecting_post_provider'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model named is_template,
     * please return it, cast to type integer."
     */
    protected $casts = [
        'is_template' => 'integer'
    ];

    /*
    returns the venue these text labels belong to
    */
    public function venue_wifi(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi');
    }

    public function delete(){
        // Delete the text labels themselves
        $result = parent::delete();

        return $result;
    }
}