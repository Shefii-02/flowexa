<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->string('media_type', 20)->nullable()->after('type');
            $table->string('media_url', 500)->nullable()->after('media_type');
            $table->string('media_id', 100)->nullable()->after('media_url');
            $table->string('media_caption', 255)->nullable()->after('media_id');
            $table->string('media_filename', 150)->nullable()->after('media_caption');
            $table->decimal('location_lat', 10, 7)->nullable()->after('media_filename');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
            $table->string('location_name', 150)->nullable()->after('location_lat');
            $table->string('location_address', 255)->nullable()->after('location_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('flow_builders');

        Schema::table('flow_nodes', function (Blueprint $t) {
            $t->dropForeign(['flow_builder_id']);
            $t->dropColumn('flow_builder_id');
        });
    }
};
