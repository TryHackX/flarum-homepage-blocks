<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Advanced Filters index: release data read from each discussion's title and first post
 * (see Meta\MetaExtractor / Meta\MetaIndexer). One row per discussion, removed with it.
 * Every filterable column is indexed — the filters run `discussions.id IN (SELECT
 * discussion_id FROM … WHERE <column> …)`, so the lookup starts from these indexes.
 */
return Migration::createTable('homepage_discussion_meta', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id')->primary();
    $table->unsignedBigInteger('size_bytes')->nullable()->index();
    $table->string('size_source', 8)->nullable();
    $table->unsignedTinyInteger('is_series')->nullable()->index();
    $table->unsignedSmallInteger('seasons')->nullable()->index();
    $table->unsignedInteger('episodes')->nullable()->index();
    $table->unsignedSmallInteger('year_start')->nullable()->index();
    $table->unsignedSmallInteger('year_end')->nullable();
    $table->unsignedSmallInteger('duration_min')->nullable()->index();
    $table->decimal('imdb_rating', 3, 1)->nullable()->index();
    $table->decimal('filmweb_rating', 3, 1)->nullable()->index();
    $table->string('signature', 16)->default('');
    $table->dateTime('indexed_at')->nullable();

    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
});
