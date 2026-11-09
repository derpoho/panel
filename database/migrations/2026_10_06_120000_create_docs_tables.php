<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The docs site (docs.{domain}): sections are the tabs along the
        // top, pages sit in named groups in each section's sidebar.
        Schema::create('doc_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('doc_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_section_id')->constrained()->cascadeOnDelete();
            $table->string('group');
            $table->string('title');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->longText('body');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->unique(['doc_section_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_pages');
        Schema::dropIfExists('doc_sections');
    }
};
