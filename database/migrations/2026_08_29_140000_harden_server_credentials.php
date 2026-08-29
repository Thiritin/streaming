<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server credentials, hashed and split in two.
 *
 * `shared_secret` was a plaintext column accepted from the query string and looked up
 * *by* the secret, so it landed in every access log on the path and any server holding
 * one could address another's endpoints. It is replaced rather than migrated: the old
 * values are in logs and in cloud-init user-data, so carrying them over would carry the
 * compromise with them. Every existing server has to be reprovisioned.
 *
 * Two credentials, not one, because metrics authority and deploy authority are different
 * powers and have to be revocable separately: rotating the deploy token must not blind
 * the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('shared_secret_hash', 64)->nullable()->after('hetzner_id');
            $table->timestamp('shared_secret_rotated_at')->nullable()->after('shared_secret_hash');
            $table->string('deploy_token_hash', 64)->nullable()->after('shared_secret_rotated_at');
            $table->timestamp('deploy_token_rotated_at')->nullable()->after('deploy_token_hash');

            $table->index('shared_secret_hash');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('shared_secret');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('shared_secret')->nullable()->after('hetzner_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['shared_secret_hash']);
            $table->dropColumn([
                'shared_secret_hash',
                'shared_secret_rotated_at',
                'deploy_token_hash',
                'deploy_token_rotated_at',
            ]);
        });
    }
};
