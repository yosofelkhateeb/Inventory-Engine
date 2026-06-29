<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion templates — optional Brief presets. Two sources:
 *   `seeded`          — curated by the team / imported via seeder (Phase 1).
 *   `auto_discovered` — surfaced by the clustering job that detects stable
 *                        recurring Brief patterns (Phase 2 of the redesign).
 *
 * Operators apply a template to pre-fill the Brief fields on a new
 * promotion. They can override every field individually before saving —
 * templates are convenience, not constraint. See design doc § "Auto-
 * discovered templates" for the Phase 2 workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name', 120);

            // Full Brief field-value snapshot stored as JSON. Keys mirror
            // the promotions table columns added in the prior migration —
            // discount_pct, discount_type, channel_mix, ad_spend_band,
            // audience, lead_announcement_days, promotion_type. Nullable
            // for any field the template intentionally leaves unset.
            $table->json('brief')->nullable();

            // Template provenance. `seeded` for hand-authored / imported;
            // `auto_discovered` for clustering-job output (Phase 2). Drives
            // the UI badge that indicates "the system noticed this pattern".
            $table->enum('source', ['seeded', 'auto_discovered'])
                ->default('seeded');

            // For auto_discovered templates, a pointer to the cluster
            // signature that produced it. Used when the same cluster
            // resurfaces after a previous dismissal — keeps the system
            // from re-prompting on a pattern the operator already rejected.
            $table->string('cluster_signature', 64)->nullable();

            // How many past campaigns the cluster was based on at the
            // moment of surfacing. Useful for the "based on N campaigns"
            // UI label and for ranking templates by representativeness.
            $table->unsignedInteger('representative_sample_size')
                ->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'source']);
            $table->unique(['tenant_id', 'cluster_signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_templates');
    }
};
