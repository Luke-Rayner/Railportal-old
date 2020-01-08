<?php

namespace UserFrosting\Sprinkle\IntelliSense\Database\Models;

use UserFrosting\Sprinkle\Core\Database\Models\Model;

class ExtendedUserAux extends Model
{
    public $timestamps = false;

    /**
     * @var string The name of the table for the current model.
     */
    protected $table = 'extended_users';

    protected $fillable = [
        'company_id',
        'primary_venue_id',
        'full_venue_view_allowed',
        'session_expiry_time'
    ];
}