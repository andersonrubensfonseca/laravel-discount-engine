<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria + snapshot.
 *
 * A coluna `snapshot` guarda o DiscountResult serializado no momento do
 * fechamento. E isso que garante que editar uma regra amanha nao reescreve
 * o valor de um pedido de ontem.
 *
 * order_id fica sem foreign key de proposito: o pacote nao sabe o nome da
 * tabela de pedidos do sistema hospedeiro.
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

            $table->foreignId('coupon_id')
                ->nullable()
                ->constrained(config('discount-engine.tables.coupons', 'discount_coupons'))
                ->nullOnDelete();

            $table->string('order_id', 64)->index();
            $table->string('customer_id', 64)->nullable();

            $table->integer('amount_cents');
            $table->json('snapshot')->nullable();

            $table->timestamps();

            // Consulta do limite por cliente.
            $table->index(['coupon_id', 'customer_id'], 'discount_usages_customer_index');

            // Idempotencia: o mesmo pedido nao registra a mesma regra duas vezes.
            $table->unique(['order_id', 'rule_id'], 'discount_usages_order_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('discount-engine.tables.usages', 'discount_usages');
    }
};
