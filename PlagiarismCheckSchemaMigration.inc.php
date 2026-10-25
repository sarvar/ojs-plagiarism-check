<?php

/**
 * @file plugins/generic/plagiarismCheck/PlagiarismCheckSchemaMigration.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckSchemaMigration
 * @brief Database tables for the plagiarism check plugin.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class PlagiarismCheckSchemaMigration extends Migration {

	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		// One row per checked or indexed file: its extracted text, check status and result
		if (!Capsule::schema()->hasTable('plagiarism_check_documents')) {
			Capsule::schema()->create('plagiarism_check_documents', function (Blueprint $table) {
				$table->bigIncrements('document_id');
				$table->bigInteger('context_id');
				$table->bigInteger('submission_id')->nullable();
				$table->bigInteger('submission_file_id')->nullable();
				$table->bigInteger('file_id')->nullable();
				// submission (manuscript file) or galley (published file)
				$table->string('source_type', 16);
				$table->string('title', 255)->nullable();
				// queued, processing, done or failed
				$table->string('status', 16);
				$table->mediumText('text')->nullable();
				$table->integer('token_count')->nullable();
				$table->decimal('score', 5, 1)->nullable();
				$table->longText('matches')->nullable();
				$table->text('error')->nullable();
				$table->string('external_provider', 32)->nullable();
				$table->string('external_id', 255)->nullable();
				$table->string('external_status', 16)->nullable();
				$table->decimal('external_score', 5, 1)->nullable();
				$table->text('external_report_url')->nullable();
				$table->datetime('date_queued');
				$table->datetime('date_checked')->nullable();
				$table->unique(['submission_file_id'], 'plagiarism_check_documents_file');
				$table->index(['status'], 'plagiarism_check_documents_status');
				$table->index(['submission_id'], 'plagiarism_check_documents_submission');
				$table->index(['context_id'], 'plagiarism_check_documents_context');
			});
		}

		// Sampled shingle hashes used to find candidate sources quickly
		if (!Capsule::schema()->hasTable('plagiarism_check_fingerprints')) {
			Capsule::schema()->create('plagiarism_check_fingerprints', function (Blueprint $table) {
				$table->bigInteger('document_id');
				$table->bigInteger('hash');
				$table->index(['hash'], 'plagiarism_check_fingerprints_hash');
				$table->index(['document_id'], 'plagiarism_check_fingerprints_document');
			});
		}
	}

	/**
	 * Reverse the migration.
	 * @return void
	 */
	public function down() {
		Capsule::schema()->dropIfExists('plagiarism_check_fingerprints');
		Capsule::schema()->dropIfExists('plagiarism_check_documents');
	}
}
