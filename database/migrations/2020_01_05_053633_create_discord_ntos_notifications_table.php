<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDiscordNtosNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('discord_ntos_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->string('guild_id');
            $table->string('channel_id');
            $table->timestamps();

            //If row exists, notifications are ON for that channel
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('discord_ntos_notifications');
    }
}
