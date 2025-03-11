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
        // 阿里云 OSS 对象跟踪表
        // 用于创建上传参数后，跟踪数据入库情况
        Schema::create('oss_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('osstracktable_type'); // 对应所属模型的类名
            $table->unsignedInteger('osstracktable_id'); // 对应所属模型的 ID
            $table->string('name'); // 对象名
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('oss_tracks');
    }
};
