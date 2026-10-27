<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id')->index();
            $table->string('name', 120);
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('funnel_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id')->index();
            $table->unsignedBigInteger('funnel_id');
            $table->unsignedInteger('version');
            $table->text('steps');
            $table->timestamp('created_at');
            $table->unique(['funnel_id', 'version']);
        });
        Schema::create('journey_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id')->index();
            $table->char('cache_key', 64)->unique();
            $table->string('status', 16)->default('scanning')->index();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('snapshot_id');
            $table->longText('payload');
            $table->longText('state')->nullable();
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('scanned')->default(0);
            $table->unsignedBigInteger('excluded')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
        Schema::create('journey_report_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id')->index();
            $table->unsignedBigInteger('report_id')->index();
            $table->string('session_key', 64);
            $table->longText('events');
            $table->boolean('truncated')->default(false);
            $table->unique(['report_id', 'session_key']);
        });
        Schema::table('events', fn (Blueprint $table) => $table->index(['website_id', 'session_key', 'received_at', 'id'], 'events_session_order'));
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropIndex('events_session_order'));
        foreach (['journey_report_sessions', 'journey_reports', 'funnel_versions', 'funnels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
