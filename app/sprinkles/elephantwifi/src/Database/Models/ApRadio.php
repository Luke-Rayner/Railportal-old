<?php
/**
 * This Class models the ApRadio model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * ApRadio Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class ApRadio extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'ap_radio';

    protected $fillable = [
        'ap_config_id',
        'radio',
        'tx_power_mode',
        'tx_power',
        'channel'
    ];

    /**
     * @var bool Enable timestamps
     */
    public $timestamps = true;

    /**
     * Get the ap_config this ap_radio belongs to
     */
    public function ap_config(){
        return $this->belongsTo('UserFrosting\Sprinkle\ElephantWifi\Database\Models\ApConfig');
    }
}