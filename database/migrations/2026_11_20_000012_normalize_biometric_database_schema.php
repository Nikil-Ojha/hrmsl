<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------
        // 1. Normalized Employee <-> Device User ID Mapping Table
        //    (Adapts to existing `employees` table; no duplicate employee table)
        // -----------------------------------------------------------------
        if (!Schema::hasTable('biometric_employee_mappings')) {
            Schema::create('biometric_employee_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->cascadeOnDelete();
                $table->string('device_user_id', 50);
                $table->string('card_number', 64)->nullable();
                $table->unsignedTinyInteger('privilege')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('source', 30)->default('hrms'); // hrms, enrollment, device_report, agent_sync
                $table->timestamp('synced_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'device_id', 'device_user_id'], 'bio_emp_map_device_pin_unique');
                $table->unique(['company_id', 'employee_id', 'device_id'], 'bio_emp_map_emp_device_unique');
                $table->index(['company_id', 'device_user_id', 'is_active'], 'bio_emp_map_company_pin_idx');
                $table->index(['device_id', 'is_active'], 'bio_emp_map_device_active_idx');
            });

            // Backfill from existing biometric_enrollments safely
            if (Schema::hasTable('biometric_enrollments')) {
                DB::statement("
                    INSERT IGNORE INTO biometric_employee_mappings
                        (company_id, employee_id, device_id, device_user_id, is_active, source, synced_at, created_by, created_at, updated_at)
                    SELECT
                        company_id,
                        employee_id,
                        device_id,
                        device_user_id,
                        CASE WHEN enrollment_status = 'removed' THEN 0 ELSE 1 END,
                        'enrollment',
                        last_synced_at,
                        enrolled_by,
                        created_at,
                        updated_at
                    FROM biometric_enrollments
                ");
            }
        }

        // -----------------------------------------------------------------
        // 2. Append-Only Command Execution & Lifecycle Audit Log
        // -----------------------------------------------------------------
        if (!Schema::hasTable('biometric_device_command_logs')) {
            Schema::create('biometric_device_command_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('command_id')->constrained('biometric_commands')->cascadeOnDelete();
                $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained('biometric_agents')->nullOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 30); // queued, sent, acknowledged, failed, expired, cancelled
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->unsignedSmallInteger('attempt_number')->default(0);
                $table->string('return_code', 50)->nullable();
                $table->string('message', 500)->nullable();
                $table->json('response_payload')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['command_id', 'occurred_at'], 'bio_cmd_logs_command_time_idx');
                $table->index(['company_id', 'event', 'occurred_at'], 'bio_cmd_logs_company_event_idx');
                $table->index(['device_id', 'occurred_at'], 'bio_cmd_logs_device_time_idx');
            });
        }

        // -----------------------------------------------------------------
        // 3. Harden & Normalize Existing Biometric Tables In-Place
        // -----------------------------------------------------------------

        // 3a. biometric_devices: composite company + branch index for dashboard filtering
        if (Schema::hasTable('biometric_devices') && !$this->hasIndex('biometric_devices', 'bio_devices_company_branch_idx')) {
            Schema::table('biometric_devices', function (Blueprint $table) {
                $table->index(['company_id', 'branch_id'], 'bio_devices_company_branch_idx');
            });
        }

        // 3b. biometric_commands: link executing agent_id + tenant status index
        if (Schema::hasTable('biometric_commands')) {
            Schema::table('biometric_commands', function (Blueprint $table) {
                if (!Schema::hasColumn('biometric_commands', 'agent_id')) {
                    $table->foreignId('agent_id')->nullable()->after('device_id')
                        ->constrained('biometric_agents')->nullOnDelete();
                }
            });
            if (!$this->hasIndex('biometric_commands', 'bio_commands_company_status_idx')) {
                Schema::table('biometric_commands', function (Blueprint $table) {
                    $table->index(['company_id', 'status', 'created_at'], 'bio_commands_company_status_idx');
                });
            }
        }

        // 3c. biometric_sync_runs: link executing agent_id + dashboard failed-24h index
        if (Schema::hasTable('biometric_sync_runs')) {
            Schema::table('biometric_sync_runs', function (Blueprint $table) {
                if (!Schema::hasColumn('biometric_sync_runs', 'agent_id')) {
                    $table->foreignId('agent_id')->nullable()->after('device_id')
                        ->constrained('biometric_agents')->nullOnDelete();
                }
            });
            if (!$this->hasIndex('biometric_sync_runs', 'bio_sync_runs_company_status_time_idx')) {
                Schema::table('biometric_sync_runs', function (Blueprint $table) {
                    $table->index(['company_id', 'status', 'started_at'], 'bio_sync_runs_company_status_time_idx');
                });
            }
        }

        // 3d. biometric_api_audit_logs: add formal company_id FK to users
        if (Schema::hasTable('biometric_api_audit_logs') && !$this->hasForeignKey('biometric_api_audit_logs', 'biometric_api_audit_logs_company_id_foreign')) {
            Schema::table('biometric_api_audit_logs', function (Blueprint $table) {
                $table->foreign('company_id', 'biometric_api_audit_logs_company_id_foreign')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        // 3e. Legacy biometric_attendance_logs: adapt in-place with tenant & device FKs
        if (Schema::hasTable('biometric_attendance_logs')) {
            Schema::table('biometric_attendance_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('biometric_attendance_logs', 'company_id')) {
                    $table->foreignId('company_id')->nullable()->after('id')
                        ->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('biometric_attendance_logs', 'device_id')) {
                    $table->foreignId('device_id')->nullable()->after('company_id')
                        ->constrained('biometric_devices')->nullOnDelete();
                }
                if (!Schema::hasColumn('biometric_attendance_logs', 'punch_id')) {
                    $table->foreignId('punch_id')->nullable()->after('device_id')
                        ->constrained('biometric_punches')->nullOnDelete();
                }
            });
            if (!$this->hasIndex('biometric_attendance_logs', 'bio_att_logs_company_date_idx')) {
                Schema::table('biometric_attendance_logs', function (Blueprint $table) {
                    $table->index(['company_id', 'log_date'], 'bio_att_logs_company_date_idx');
                });
            }
            if (!$this->hasIndex('biometric_attendance_logs', 'bio_att_logs_device_dt_idx')) {
                Schema::table('biometric_attendance_logs', function (Blueprint $table) {
                    $table->index(['device_id', 'log_datetime'], 'bio_att_logs_device_dt_idx');
                });
            }
        }

        // 3f. Legacy biometric_device_commands: adapt in-place with tenant & device FKs
        if (Schema::hasTable('biometric_device_commands')) {
            Schema::table('biometric_device_commands', function (Blueprint $table) {
                if (!Schema::hasColumn('biometric_device_commands', 'company_id')) {
                    $table->foreignId('company_id')->nullable()->after('id')
                        ->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('biometric_device_commands', 'device_id')) {
                    $table->foreignId('device_id')->nullable()->after('company_id')
                        ->constrained('biometric_devices')->nullOnDelete();
                }
            });
            if (!$this->hasIndex('biometric_device_commands', 'bio_dev_cmds_company_status_idx')) {
                Schema::table('biometric_device_commands', function (Blueprint $table) {
                    $table->index(['company_id', 'status'], 'bio_dev_cmds_company_status_idx');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('biometric_device_commands')) {
            Schema::table('biometric_device_commands', function (Blueprint $table) {
                if (Schema::hasColumn('biometric_device_commands', 'device_id')) {
                    $table->dropConstrainedForeignId('device_id');
                }
                if (Schema::hasColumn('biometric_device_commands', 'company_id')) {
                    $table->dropConstrainedForeignId('company_id');
                }
            });
            if ($this->hasIndex('biometric_device_commands', 'bio_dev_cmds_company_status_idx')) {
                Schema::table('biometric_device_commands', fn (Blueprint $t) => $t->dropIndex('bio_dev_cmds_company_status_idx'));
            }
        }

        if (Schema::hasTable('biometric_attendance_logs')) {
            Schema::table('biometric_attendance_logs', function (Blueprint $table) {
                if (Schema::hasColumn('biometric_attendance_logs', 'punch_id')) {
                    $table->dropConstrainedForeignId('punch_id');
                }
                if (Schema::hasColumn('biometric_attendance_logs', 'device_id')) {
                    $table->dropConstrainedForeignId('device_id');
                }
                if (Schema::hasColumn('biometric_attendance_logs', 'company_id')) {
                    $table->dropConstrainedForeignId('company_id');
                }
            });
            if ($this->hasIndex('biometric_attendance_logs', 'bio_att_logs_device_dt_idx')) {
                Schema::table('biometric_attendance_logs', fn (Blueprint $t) => $t->dropIndex('bio_att_logs_device_dt_idx'));
            }
            if ($this->hasIndex('biometric_attendance_logs', 'bio_att_logs_company_date_idx')) {
                Schema::table('biometric_attendance_logs', fn (Blueprint $t) => $t->dropIndex('bio_att_logs_company_date_idx'));
            }
        }

        if (Schema::hasTable('biometric_api_audit_logs') && $this->hasForeignKey('biometric_api_audit_logs', 'biometric_api_audit_logs_company_id_foreign')) {
            Schema::table('biometric_api_audit_logs', fn (Blueprint $t) => $t->dropForeign('biometric_api_audit_logs_company_id_foreign'));
        }

        if (Schema::hasTable('biometric_sync_runs')) {
            if ($this->hasIndex('biometric_sync_runs', 'bio_sync_runs_company_status_time_idx')) {
                Schema::table('biometric_sync_runs', function (Blueprint $t) {
                    if (!$this->hasIndex('biometric_sync_runs', 'biometric_sync_runs_company_id_foreign')) {
                        $t->index('company_id', 'biometric_sync_runs_company_id_foreign');
                    }
                    $t->dropIndex('bio_sync_runs_company_status_time_idx');
                });
            }
            Schema::table('biometric_sync_runs', function (Blueprint $table) {
                if (Schema::hasColumn('biometric_sync_runs', 'agent_id')) {
                    $table->dropConstrainedForeignId('agent_id');
                }
            });
        }

        if (Schema::hasTable('biometric_commands')) {
            if ($this->hasIndex('biometric_commands', 'bio_commands_company_status_idx')) {
                Schema::table('biometric_commands', function (Blueprint $t) {
                    if (!$this->hasIndex('biometric_commands', 'biometric_commands_company_id_foreign')) {
                        $t->index('company_id', 'biometric_commands_company_id_foreign');
                    }
                    $t->dropIndex('bio_commands_company_status_idx');
                });
            }
            Schema::table('biometric_commands', function (Blueprint $table) {
                if (Schema::hasColumn('biometric_commands', 'agent_id')) {
                    $table->dropConstrainedForeignId('agent_id');
                }
            });
        }

        if (Schema::hasTable('biometric_devices') && $this->hasIndex('biometric_devices', 'bio_devices_company_branch_idx')) {
            Schema::table('biometric_devices', fn (Blueprint $t) => $t->dropIndex('bio_devices_company_branch_idx'));
        }

        Schema::dropIfExists('biometric_device_command_logs');
        Schema::dropIfExists('biometric_employee_mappings');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $indexName]
        );

        return !empty($rows);
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$table, $constraintName, 'FOREIGN KEY']
        );

        return !empty($rows);
    }
};
