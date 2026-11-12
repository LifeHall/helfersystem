<?php

declare(strict_types=1);

namespace Helfersystem\Startseite\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Mails to many people, sent in small batches (the hosting ends long requests)
 */
class StartseiteMailJobs extends Migration
{
    public function up(): void
    {
        if ($this->schema->hasTable('startseite_mail_jobs')) {
            return;
        }

        $this->schema->create('startseite_mail_jobs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('handler');
            $table->string('privilege');
            $table->string('title');
            $table->json('payload');
            $table->json('recipients');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->json('failed');
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('startseite_mail_jobs');
    }
}
