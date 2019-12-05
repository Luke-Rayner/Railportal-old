<?php
/**
 * This Class models the PdfPreset model following the table definition
 */
namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * PdfPreset Class
 *
 * @package GEO-Sense
 * @author Luke Rayner/ElephantWiFi
 */
class PdfPreset extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'pdf_preset';

    protected $fillable = [
        'user_id',
        'name'
    ];

    /**
     * returns all modules used by this preset
     */
    public function pdf_modules(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\PdfModule', 'pdf_module_preset');
    }

    /**
     * returns all zones used by this preset
     */
    public function zones(){
        return $this->belongsToMany('UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone', 'pdf_zone_preset');
    }  
}