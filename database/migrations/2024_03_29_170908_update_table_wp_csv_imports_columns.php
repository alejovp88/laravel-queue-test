<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wp_csv_imports', function (Blueprint $table) {
            $table->integer('total_records')->default(0);
            $table->integer('success_records')->default(0);
            $table->integer('fail_records')->default(0);
            $table->string('opt_status')->nullable();
            $table->string('opt_full_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wp_csv_imports', function (Blueprint $table) {
            $table->dropColumn('total_records');
            $table->dropColumn('success_records');
            $table->dropColumn('fail_records');
            $table->dropColumn('opt_status');
            $table->dropColumn('opt_full_name');
        });
    }
};
