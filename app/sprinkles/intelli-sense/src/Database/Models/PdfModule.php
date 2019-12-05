<?php
/**
 * This Class models the PdfModule model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * PdfModule Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class PdfModule extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'pdf_module';

    protected $fillable = [
        'name',
        'checkbox_id',
        'data_value'
    ];

    /**
     * returns all presets that use this module
     */
    public function pdf_presets(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\PdfPreset', 'pdf_module_preset');
    }   
}