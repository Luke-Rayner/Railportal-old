<?php
namespace UserFrosting\Sprinkle\Account\Database\Migrations\v440;

use UserFrosting\System\Bakery\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class ExtendedUsersTable extends Migration
{
    public $dependencies = [
        '\UserFrosting\Sprinkle\Account\Database\Migrations\v400\UsersTable'
    ];

    public function up()
    {
        if (!$this->schema->hasTable('extended_users')) {
            $this->schema->create('extended_users', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('company_id')->unsigned();
                $table->integer('primary_venue_id')->unsigned();
                $table->tinyInteger('full_venue_view_allowed')->unsigned();
                $table->string('session_expiry_time', 32);

                $table->engine = 'InnoDB';
                $table->collation = 'utf8_unicode_ci';
                $table->charset = 'utf8';
                $table->foreign('id')->references('id')->on('users');
                $table->index('company_id');
                $table->index('primary_venue_id');
            });
        }
    }

    public function down()
    {
        $this->schema->drop('extended_users');
    }
}
