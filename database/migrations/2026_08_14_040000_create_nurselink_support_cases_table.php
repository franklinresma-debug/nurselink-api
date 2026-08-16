<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nurselink_support_cases')) {
            return;
        }

        Schema::create(
            'nurselink_support_cases',
            function (Blueprint $table): void {
                $table->id();
                $table->string('case_number', 40)->unique();

                $this->userId(
                    $table,
                    'member_user_id'
                );

                $table->unsignedBigInteger(
                    'organization_id'
                )->nullable()->index();

                $table->string(
                    'source',
                    30
                )->default('admin')->index();

                $table->string(
                    'category',
                    40
                )->default('other')->index();

                $table->string(
                    'priority',
                    20
                )->default('normal')->index();

                $table->string(
                    'status',
                    30
                )->default('open')->index();

                $table->string(
                    'subject',
                    190
                );

                $table->text(
                    'description'
                )->nullable();

                $this->userId(
                    $table,
                    'assigned_admin_user_id'
                );

                $this->userId(
                    $table,
                    'created_by_user_id'
                );

                $table->text(
                    'internal_note'
                )->nullable();

                $table->text(
                    'resolution_summary'
                )->nullable();

                $table->timestamp(
                    'last_activity_at'
                )->nullable()->index();

                $table->timestamp(
                    'resolved_at'
                )->nullable();

                $table->timestamp(
                    'closed_at'
                )->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'status',
                        'priority',
                        'last_activity_at',
                    ],
                    'nl_support_status_priority_activity'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_support_cases'
        );
    }

    private function userId(
        Blueprint $table,
        string $name
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
                    $table->tinyInteger(
                        $name
                    );
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;

            case 'smallint':
                $definition =
                    $table->smallInteger(
                        $name
                    );
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;

            case 'mediumint':
                $definition =
                    $table->mediumInteger(
                        $name
                    );
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;

            case 'int':
            case 'integer':
                $definition =
                    $table->integer(
                        $name
                    );
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;

            case 'bigint':
                $definition =
                    $table->bigInteger(
                        $name
                    );
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;

            case 'char':
                $length = (int) (
                    $column
                        ->CHARACTER_MAXIMUM_LENGTH
                    ?: 36
                );

                $definition =
                    $table->char(
                        $name,
                        max(
                            1,
                            min(
                                $length,
                                255
                            )
                        )
                    );
                break;

            case 'varchar':
                $length = (int) (
                    $column
                        ->CHARACTER_MAXIMUM_LENGTH
                    ?: 191
                );

                $definition =
                    $table->string(
                        $name,
                        max(
                            1,
                            min(
                                $length,
                                512
                            )
                        )
                    );
                break;

            default:
                throw new RuntimeException(
                    'Unsupported users.id data type: '
                    . $dataType
                );
        }

        $definition
            ->nullable()
            ->index();
    }
};
