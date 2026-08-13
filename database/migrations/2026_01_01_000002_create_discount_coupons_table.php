<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma regra pode ter varios codigos. Isso cobre de graca o caso de
 * campanha com codigos unicos por cliente (10 mil codigos, uma regra so).
 *
 * used_count e a fonte de verdade para o limite: e incrementado com lock
 * no fechamento do pedido, nunca por contagem de linhas em discount_usages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')
                ->constrained(config('discount-engine.tables.rules', 'discount_rules'))
                ->cascadeOnDelete();

            $table->string('code', 60)->unique();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('discount-engine.tables.coupons', 'discount_coupons');
    }
};
