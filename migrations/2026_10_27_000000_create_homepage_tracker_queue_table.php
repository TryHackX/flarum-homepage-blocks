<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('homepage_tracker_queue', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id')->primary();
    $table->unsignedInteger('post_id')->nullable();
    $table->string('reason', 16);
    $table->unsignedInteger('seq')->default(1);
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->dateTime('next_at')->index();
    $table->string('last_error', 300)->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
});
