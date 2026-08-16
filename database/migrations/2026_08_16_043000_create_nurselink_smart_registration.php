<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_smart_registration_profiles')) {
            Schema::create('nurselink_smart_registration_profiles', function (Blueprint $table): void {
                $table->id();
                $this->userIdDefinition($table);
                $table->string('first_name', 120)->nullable();
                $table->string('middle_name', 120)->nullable();
                $table->string('last_name', 120)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('sex', 40)->nullable();
                $table->string('nationality', 120)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('address_line1', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('professional_title', 150)->nullable();
                $table->unsignedSmallInteger('years_experience')->nullable();
                $table->string('current_position', 150)->nullable();
                $table->string('current_employer', 190)->nullable();
                $table->string('specialty', 150)->nullable();
                $table->string('primary_license_number', 160)->nullable();
                $table->string('primary_license_country', 120)->nullable();
                $table->date('primary_license_expiry')->nullable();
                $table->string('highest_nursing_education', 190)->nullable();
                $table->unsignedSmallInteger('graduation_year')->nullable();
                $table->json('confirmed_sources')->nullable();
                $table->timestamp('last_extracted_at')->nullable();
                $table->timestamps();

                $table->unique('user_id');
            });
        }

        if (! Schema::hasTable('nurselink_smart_registration_documents')) {
            Schema::create('nurselink_smart_registration_documents', function (Blueprint $table): void {
                $table->id();
                $this->userIdDefinition($table);
                $table->string('original_name', 255);
                $table->string('storage_path', 500);
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->char('sha256', 64);
                $table->string('document_type', 80)->default('other');
                $table->string('extraction_status', 40)->default('pending');
                $table->json('extracted_fields')->nullable();
                $table->text('extraction_message')->nullable();
                $table->timestamp('extracted_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at'], 'nl_sr_docs_user_created_idx');
                $table->index(['user_id', 'document_type'], 'nl_sr_docs_user_type_idx');
                $table->index(['sha256'], 'nl_sr_docs_sha_idx');
            });
        }

        // A prior v5.5.7 attempt can leave this table present because MySQL DDL
        // is not transactionally rolled back. Reconcile required indexes by
        // column sequence so rerunning the migration is safe and does not
        // duplicate an index that MySQL already created before the failure.
        if (Schema::hasTable('nurselink_smart_registration_documents')) {
            $this->ensureIndex(
                'nurselink_smart_registration_documents',
                ['user_id', 'created_at'],
                'nl_sr_docs_user_created_idx'
            );
            $this->ensureIndex(
                'nurselink_smart_registration_documents',
                ['user_id', 'document_type'],
                'nl_sr_docs_user_type_idx'
            );
            $this->ensureIndex(
                'nurselink_smart_registration_documents',
                ['sha256'],
                'nl_sr_docs_sha_idx'
            );
        }

        if (Schema::hasTable('nurselink_memberships')) {
            Schema::table('nurselink_memberships', function (Blueprint $table): void {
                if (! Schema::hasColumn('nurselink_memberships', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable()->after('reviewed_at');
                }
                if (! Schema::hasColumn('nurselink_memberships', 'resubmitted_at')) {
                    $table->timestamp('resubmitted_at')->nullable()->after('submitted_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nurselink_memberships')) {
            $columns = array_values(array_filter([
                'submitted_at',
                'resubmitted_at',
            ], fn (string $column): bool => Schema::hasColumn('nurselink_memberships', $column)));

            if ($columns !== []) {
                Schema::table('nurselink_memberships', fn (Blueprint $table): mixed => $table->dropColumn($columns));
            }
        }

        Schema::dropIfExists('nurselink_smart_registration_documents');
        Schema::dropIfExists('nurselink_smart_registration_profiles');
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        $rows = DB::select(
            "SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$tableName]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) $row->INDEX_NAME;
            $indexes[$name][] = (string) $row->COLUMN_NAME;
        }

        foreach ($indexes as $name => $existingColumns) {
            if ($name === $indexName || array_values($existingColumns) === array_values($columns)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function userIdDefinition(Blueprint $table): void
    {
        $column = DB::selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );

        if (! $column) {
            throw new RuntimeException('Unable to inspect users.id.');
        }

        $dataType = strtolower((string) $column->DATA_TYPE);
        $columnType = strtolower((string) $column->COLUMN_TYPE);
        $unsigned = str_contains($columnType, 'unsigned');

        switch ($dataType) {
            case 'tinyint':
                $definition = $table->tinyInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition = $table->smallInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition = $table->mediumInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition = $table->integer('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition = $table->bigInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'char':
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 36);
                $table->char('user_id', max(1, min($length, 255)));
                break;
            case 'varchar':
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 191);
                $table->string('user_id', max(1, min($length, 512)));
                break;
            default:
                throw new RuntimeException('Unsupported users.id data type for NurseLink: ' . $dataType);
        }

        $table->index('user_id');
    }
};
