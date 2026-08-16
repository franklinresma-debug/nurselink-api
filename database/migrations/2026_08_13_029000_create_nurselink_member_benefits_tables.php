<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_member_benefits')) {
            Schema::create(
                'nurselink_member_benefits',
                function (Blueprint $table): void {
                    $table->id();
                    $table->string('title', 190);
                    $table->string('slug', 190)->unique();
                    $table->string('category', 60)
                        ->default('resource');
                    $table->string('provider_name', 190)
                        ->nullable();
                    $table->text('description')->nullable();
                    $table->text('eligibility_note')->nullable();
                    $table->text('terms')->nullable();
                    $table->string('external_url', 512)
                        ->nullable();
                    $table->boolean('requires_request')
                        ->default(false);
                    $table->unsignedInteger('max_requests')
                        ->nullable();
                    $table->timestamp('starts_at')
                        ->nullable();
                    $table->timestamp('ends_at')
                        ->nullable();
                    $table->string('status', 30)
                        ->default('draft');
                    $table->string('created_by', 191)
                        ->nullable();
                    $table->string('updated_by', 191)
                        ->nullable();
                    $table->timestamps();

                    $table->index(['status', 'category']);
                    $table->index(['starts_at', 'ends_at']);
                }
            );
        }

        if (! Schema::hasTable('nurselink_benefit_requests')) {
            Schema::create(
                'nurselink_benefit_requests',
                function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition($table);
                    $table->unsignedBigInteger('benefit_id');
                    $table->string('status', 30)
                        ->default('requested');
                    $table->text('member_note')->nullable();
                    $table->text('admin_note')->nullable();
                    $table->timestamp('requested_at')->nullable();
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamp('declined_at')->nullable();
                    $table->timestamp('fulfilled_at')->nullable();
                    $table->timestamp('cancelled_at')->nullable();
                    $table->timestamps();

                    $table->unique(
                        ['benefit_id', 'user_id'],
                        'nl_benefit_user_unique'
                    );
                    $table->index(['benefit_id', 'status']);
                    $table->index(['user_id', 'status']);
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_benefit_requests'
        );
        Schema::dropIfExists(
            'nurselink_member_benefits'
        );
    }

    private function userIdDefinition(
        Blueprint $table
    ): void {
        $column = DB::selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );

        if (! $column) {
            throw new RuntimeException(
                'Unable to inspect users.id.'
            );
        }

        $dataType = strtolower(
            (string) $column->DATA_TYPE
        );
        $columnType = strtolower(
            (string) $column->COLUMN_TYPE
        );
        $unsigned = str_contains(
            $columnType,
            'unsigned'
        );

        switch ($dataType) {
            case 'tinyint':
                $definition =
                    $table->tinyInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition =
                    $table->smallInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition =
                    $table->mediumInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition =
                    $table->integer('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition =
                    $table->bigInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'char':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 36
                );
                $table->char(
                    'user_id',
                    max(1, min($length, 255))
                );
                break;
            case 'varchar':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 191
                );
                $table->string(
                    'user_id',
                    max(1, min($length, 512))
                );
                break;
            default:
                throw new RuntimeException(
                    'Unsupported users.id data type for NurseLink: '
                    . $dataType
                );
        }

        $table->index('user_id');
    }
};
