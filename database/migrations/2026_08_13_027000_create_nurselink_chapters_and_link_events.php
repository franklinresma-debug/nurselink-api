<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_chapters')) {
            Schema::create(
                'nurselink_chapters',
                function (Blueprint $table): void {
                    $table->id();
                    $table->string('name', 190);
                    $table->string('slug', 190)->unique();
                    $table->string('chapter_type', 50)
                        ->default('regional');
                    $table->string('region', 160)->nullable();
                    $table->string('country', 120)
                        ->default('Philippines');
                    $table->string('city', 120)->nullable();
                    $table->text('description')->nullable();
                    $table->string('contact_email', 190)->nullable();
                    $table->string('status', 30)
                        ->default('draft');
                    $table->boolean('member_join_enabled')
                        ->default(true);
                    $table->string('created_by', 191)->nullable();
                    $table->string('updated_by', 191)->nullable();
                    $table->timestamps();

                    $table->index(['status', 'chapter_type']);
                    $table->index(['country', 'region']);
                }
            );
        }

        if (! Schema::hasTable('nurselink_chapter_memberships')) {
            Schema::create(
                'nurselink_chapter_memberships',
                function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition($table);
                    $table->unsignedBigInteger('chapter_id');
                    $table->string('status', 30)
                        ->default('pending');
                    $table->string('chapter_role', 40)
                        ->default('member');
                    $table->boolean('is_primary')
                        ->default(false);
                    $table->timestamp('requested_at')->nullable();
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamp('declined_at')->nullable();
                    $table->timestamp('inactive_at')->nullable();
                    $table->text('notes')->nullable();
                    $table->timestamps();

                    $table->unique(
                        ['chapter_id', 'user_id'],
                        'nl_chapter_user_unique'
                    );
                    $table->index(['chapter_id', 'status']);
                    $table->index(['user_id', 'status']);
                    $table->index(['user_id', 'is_primary']);
                }
            );
        }

        if (
            Schema::hasTable('nurselink_events')
            && ! Schema::hasColumn(
                'nurselink_events',
                'chapter_id'
            )
        ) {
            Schema::table(
                'nurselink_events',
                function (Blueprint $table): void {
                    $table->unsignedBigInteger('chapter_id')
                        ->nullable()
                        ->after('id');

                    $table->index(
                        ['chapter_id', 'status', 'starts_at'],
                        'nl_events_chapter_status_date'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('nurselink_events')
            && Schema::hasColumn(
                'nurselink_events',
                'chapter_id'
            )
        ) {
            Schema::table(
                'nurselink_events',
                function (Blueprint $table): void {
                    $table->dropIndex(
                        'nl_events_chapter_status_date'
                    );
                    $table->dropColumn('chapter_id');
                }
            );
        }

        Schema::dropIfExists(
            'nurselink_chapter_memberships'
        );
        Schema::dropIfExists('nurselink_chapters');
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
