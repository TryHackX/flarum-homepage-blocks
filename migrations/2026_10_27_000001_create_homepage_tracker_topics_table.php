<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('homepage_tracker_topics', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id')->primary();
    $table->unsignedInteger('post_id');
    $table->text('hashes');
    $table->boolean('visible')->default(true)->index();
    $table->string('sha', 40)->nullable();
    $table->string('state', 16)->nullable()->index();
    $table->string('status', 32)->nullable();
    $table->string('detail', 300)->nullable();
    $table->dateTime('sent_at')->nullable()->index();
});
