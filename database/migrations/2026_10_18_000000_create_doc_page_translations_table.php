<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A docs page in another language, made by docs:translate. Shown
        // only while source_hash still matches the English page.
        Schema::create('doc_page_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_page_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->longText('body');
            $table->string('source_hash', 64);
            $table->timestamps();
            $table->unique(['doc_page_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_page_translations');
    }
};
