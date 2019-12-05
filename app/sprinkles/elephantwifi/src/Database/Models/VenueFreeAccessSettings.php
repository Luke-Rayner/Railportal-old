<?php
/**
 * This Class models the VenueFreeAccessSettings model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VenueFreeAccessSettings Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class VenueFreeAccessSettings extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue_free_access_settings';

    protected $fillable = [
        'is_template',
        'venue_wifi_idIndex',
        'activeIndex',
        'primary_method',
        'auth_duration',
        'redirect_url',
        'redirect_timeout',
        'registration_duration',
        'motd_content',
        'speed_limit_down',
        'speed_limit_up',
        'data_transfer_limit',
        'data_consent_text',
        'marketing_consent_text',
        'location_consent_text',
        'required_location_consent',
        'license_agreement_file_uuid',
        'license_agreement_file_name',
        'privacy_policy_file_uuid',
        'privacy_policy_file_name',
        'email_checker_api_key',
        'form_firstname',
        'form_lastname',
        'form_email',
        'form_gender',
        'form_birth_date',
        'form_postcode',
        'required_firstname',
        'required_lastname',
        'required_email',
        'required_gender',
        'required_birth_date',
        'required_postcode',
        'social_auth_temp_auth_duration',
        'social_auth_shared_account',
        'social_auth_enable_facebook',
        'social_auth_enable_twitter',
        'social_auth_enable_registration_fallback',
        'mailing_list',
        'marketing_reshow_time'
    ];

    /**
     * The $casts array is telling Eloquent: "Every time I access a property on this model
     * named blabla, please return it cast to type integer."
     */
    protected $casts = [
       'is_template' => 'integer',
       'active' => 'integer',
       'auth_duration' => 'integer',
       'registration_duration' => 'integer',
       'form_firstname' => 'integer',
       'form_lastname' => 'integer',
       'form_email' => 'integer',
       'form_gender' => 'integer',
       'form_birth_date' => 'integer',
       'form_postcode' => 'integer',
       'required_firstname' => 'integer',
       'required_lastname' => 'integer',
       'required_email' => 'integer',
       'required_gender' => 'integer',
       'required_birth_date' => 'integer',
       'required_postcode' => 'integer',
       'social_auth_temp_auth_duration' => 'integer',
       'social_auth_shared_account' => 'integer',
       'oauthd_server_id' => 'integer',
       'social_auth_enable_facebook' => 'integer',
       'social_auth_enable_twitter' => 'integer',
       'social_auth_enable_linkedin' => 'integer',
       'social_auth_enable_googleplus' => 'integer',
       'social_auth_enable_registration_fallback' => 'integer',
       'mailchimp_double_opt_in' => 'integer'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
    * returns the venue these text labels belong to
    */
    public function venue_wifi(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi');
    }

    /**
     * returns the OAuth server associated with these settings
     */
    public function oauthd_server()
    {
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\OAuthServer');
    }

    public function delete(){
        // Delete the settings themselves
        $result = parent::delete();

        return $result;
    }
}