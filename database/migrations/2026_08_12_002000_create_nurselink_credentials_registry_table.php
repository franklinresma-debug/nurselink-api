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
            'nurselink_credentials_registry',
            function (): void {
                Schema::create('nurselink_credentials_registry', function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition($table);

                    $table->string('credential_type', 80);
                    $table->string('title', 190);
                    $table->string('issuing_body', 190)->nullable();
                    $table->string('credential_number', 160)->nullable();
                    $table->string('country', 120)->nullable();
                    $table->date('issue_date')->nullable();
                    $table->date('expiry_date')->nullable();
                    $table->string('verification_status', 40)->default('unverified');
                    $table->text('notes')->nullable();
                    $table->timestamps();

                    $table->index(['user_id', 'credential_type']);
                    $table->index(['user_id', 'expiry_date']);
                    $table->index(['user_id', 'verification_status']);
                });
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_credentials_registry');
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
                throw new RuntimeException(
                    'Unsupported users.id data type for NurseLink: ' . $dataType
                );
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

        if (! $user || ! $child) {
            return true;
        }

        $userData = strtolower((string) $user->DATA_TYPE);
        $childData = strtolower((string) $child->DATA_TYPE);

        if ($userData !== $childData) {
            return true;
        }

        if (in_array($userData, ['tinyint','smallint','mediumint','int','integer','bigint'], true)) {
            $userUnsigned = str_contains(strtolower((string) $user->COLUMN_TYPE), 'unsigned');
            $childUnsigned = str_contains(strtolower((string) $child->COLUMN_TYPE), 'unsigned');

            return $userUnsigned !== $childUnsigned;
        }

        if (in_array($userData, ['char','varchar'], true)) {
            return (int) $user->CHARACTER_MAXIMUM_LENGTH !== (int) $child->CHARACTER_MAXIMUM_LENGTH;
        }

        return false;
    }

};
