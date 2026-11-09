<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documents staff send to customers: the PDF's text, and the email
        // that goes with it when it's sent on its own.
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 20); // dpa, sla, security, other
            $table->string('description')->nullable();
            $table->string('title');
            $table->longText('body');
            $table->string('email_subject');
            $table->text('email_body');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One email of documents: who sent it to whom, what it said, and a
        // copy of each PDF exactly as it went out.
        Schema::create('document_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('recipients');
            $table->json('bcc')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->json('documents');
            $table->timestamp('created_at')->nullable();
            $table->index(['workspace_id', 'id']);
        });

        $now = now();
        foreach ([
            ['dpa', 'Data processing agreement', 'The DPA, filled in with the customer’s company and ready to sign.', 'Data processing agreement'],
            ['sla', 'Service level agreement', 'The Business plan’s 99.9% uptime commitment and service credits.', 'Service level agreement'],
        ] as [$kind, $name, $description, $title]) {
            [$subject, $email] = explode("\n---\n", (string) file_get_contents(database_path("data/document-templates/{$kind}-email.md")), 2);

            DB::table('document_templates')->insert([
                'name' => $name,
                'kind' => $kind,
                'description' => $description,
                'title' => $title,
                'body' => file_get_contents(database_path("data/document-templates/{$kind}.md")),
                'email_subject' => trim($subject),
                'email_body' => trim($email),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sends');
        Schema::dropIfExists('document_templates');
    }
};
