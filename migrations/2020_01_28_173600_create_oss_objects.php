<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOssObjects extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 阿里云 OSS 对象表
        Schema::create('oss_objects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ossobjectable_type'); // 对应所属模型的类名
            $table->unsignedInteger('ossobjectable_id'); // 对应所属模型的 ID
            $table->string('group'); // 分组名
            $table->string('name'); // 对象名
            $table->unsignedInteger('size'); // 对象大小
            $table->string('mimeType')->nullable(); // mimeType
            $table->string('imageInfo')->nullable(); // 图片信息 JSON（height, width, format)
            $table->unsignedInteger('sort'); // 排序
            $table->longText('data'); // 附加数据
            $table->timestamps();

            $table->index(['ossobjectable_type', 'ossobjectable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('oss_objects');
    }
}
