<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->recreateEmptyIncompatibleTable(
            'nurselink_interviews',
            function (): void {
                Schema::create('nurselink_interviews', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('job_application_id');
                    $table->unsignedBigInteger('partner_organization_id');
                    $this->userIdDefinition($table);

                    $table->timestamp('scheduled_start');
                    $table->timestamp('scheduled_end')->nullable();
                    $table->string('timezone', 80)->default('Asia/Manila');
                    $table->string('mode', 20);
                    $table->string('location_or_link', 512)->nullable();
                    $table->string('status', 30)->default('proposed');

                    $table->text('partner_notes')->nullable();
                    $table->text('candidate_notes')->nullable();

                    $table->timestamp('confirmed_at')->nullable();
                    $table->timestamp('reschedule_requested_at')->nullable();
                    $table->timestamp('cancelled_at')->nullable();
                    $table->timestamp('completed_at')->nullable();

                    $table->timestamps();

                    $table->index(['job_application_id', 'scheduled_start'], 'nli_application_start_idx');
                    $table->index(['partner_organization_id', 'scheduled_start'], 'nli_partner_start_idx');
                    $table->index(['status', 'scheduled_start'], 'nli_status_start_idx');
                });
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_interviews');
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

    private function recreateEmptyIncompatibleTable(string $tableName, callable $builder): void
    {
        if (! Schema::hasTable($tableName)) {
            $builder();
            return;
        }

        $rows = (int) DB::table($tableName)->count();

        if ($rows === 0 && $this->userIdColumnIsIncompatible($tableName)) {
            Schema::drop($tableName);
            $builder();
        }
    }

    private function userIdColumnIsIncompatible(string $tableName): bool
    {
        $user = DB::selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );

        $child = DB::selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'user_id'
             LIMIT 1",
            [$tableName]
        );

        if (! $user || ! $child) return true;

        $userData = strtolower((string) $user->DATA_TYPE);
        $childData = strtolower((string) $child->DATA_TYPE);

        if ($userData !== $childData) return true;

        if (in_array($userData, ['tinyint','smallint','mediumint','int','integer','bigint'], true)) {
            return str_contains(strtolower((string) $user->COLUMN_TYPE), 'unsigned')
                !== str_contains(strtolower((string) $child->COLUMN_TYPE), 'unsigned');
        }

        if (in_array($userData, ['char','varchar'], true)) {
            return (int) $user->CHARACTER_MAXIMUM_LENGTH !== (int) $child->CHARACTER_MAXIMUM_LENGTH;
        }

        return false;
    }
};
