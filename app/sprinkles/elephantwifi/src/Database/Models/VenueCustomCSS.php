<?php
/**
 * This Class models the VenueCustomCSS model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * VenueCustomCSS Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class VenueCustomCSS extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'venue_captive_portal_custom_css';

    protected $fillable = [
        'is_template',
        'venue_wifi_id',
        'active',
        'panel_border_color',
        'panel_bg_color',
        'panel_header_bg_color',
        'text_color',
        'hyperlink_color',
        'border_radius',
        'button_radius',
        'custom_logo_file_uuid',
        'custom_logo_file_name',
        'custom_background_file_name',
        'custom_background_file_uuid',
        'css'
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
        'is_template'   => 'integer',
        'active'        => 'integer',
        'border_radius' => 'integer',
        'button_radius' => 'integer'
    ];

    /*
    returns the venue these text labels belong to
    */
    public function venue_wifi(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Model\VenueWifi');
    }

    public function delete(){
        // Delete the text labels themselves
        $result = parent::delete();

        return $result;
    }
}