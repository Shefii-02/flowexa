<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $table->string('disk', 30)->default('public');
            $table->string('path', 500);
            $table->string('url', 500);
            $table->string('mime_type', 100)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->timestamps();
            $table->index('company_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_limit_bytes')->default(5368709120)->after('status'); // 5GB default
            $table->unsignedBigInteger('storage_used_bytes')->default(0)->after('storage_limit_bytes');
        });


        Schema::table('flow_nodes', function (Blueprint $table) {

            $table->string('dynamic_api_url', 500)->nullable()->after('location_address');
            $table->string('dynamic_api_method', 10)->nullable()->after('dynamic_api_url');
            $table->text('dynamic_api_headers')->nullable()->after('dynamic_api_method');
            $table->string('dynamic_label_field', 100)->nullable()->after('dynamic_api_headers');
            $table->string('dynamic_value_field', 100)->nullable()->after('dynamic_label_field');
            $table->string('dynamic_description_field', 255)->nullable()->after('dynamic_value_field');
            $table->string('dynamic_image_field', 100)->nullable()->after('dynamic_description_field');
            $table->string('dynamic_subtitle_field', 100)->nullable()->after('dynamic_image_field');
        });
    }
    public function down(): void
    {
        //
        Schema::dropIfExists('media_assets');

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('storage_limit_bytes');
            $t->dropColumn('storage_used_bytes');
        });


        Schema::table('flow_nodes', function (Blueprint $t) {
            $t->dropColumn('dynamic_image_field');
            $t->dropColumn('dynamic_subtitle_field');
            $t->dropColumn('dynamic_api_url');
            $t->dropColumn('dynamic_api_method');
            $t->dropColumn('dynamic_api_headers');
            $t->dropColumn('dynamic_label_field');
            $t->dropColumn('dynamic_value_field');
            $t->dropColumn('dynamic_description_field');

        });
    }
};
