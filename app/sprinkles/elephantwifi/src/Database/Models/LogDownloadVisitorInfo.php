<?php
/**
 * This Class models the LogDownloadVisitorInfo model following the table definition
 */
namespace UserFrosting\Sprinkle\ElephantWifi\Database\Models;

use \Illuminate\Database\Capsule\Manager as Capsule;
use UserFrosting\Sprinkle\Core\Database\Models\Model;

/**
 * LogDownloadVisitorInfo Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
class LogDownloadVisitorInfo extends Model
{
    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'log_download_visitor_info';

    protected $fillable = [
        'user_id',
        'ts'
    ];

    /**
     * Get the user this log belongs to.
     */
    public function user() {
        return $this->hasOne('UserFrosting\Sprinkle\ElephantWifi\Database\Models\ExtendedUser');
    }
}