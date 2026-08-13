<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A arvore de condicoes e a lista de acoes ficam em colunas JSON.
 *
 * Decisao revisada em relacao ao plano inicial: eu havia proposto tabelas
 * separadas para condicoes e acoes. Como o painel monta arvores aninhadas
 * — "(A e B) ou C" — normalizar isso exigiria self-join com parent_id e uma
 * hidratacao recursiva bem mais fragil. O custo do JSON e nao dar para
 * perguntar em SQL "quais regras usam a categoria 12"; se isso virar
 * necessidade, resolve-se com uma tabela de indice lateral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('trigger', 20)->index();          // coupon | automatic
            $table->unsignedInteger('priority')->default(100);

            $table->string('combination_mode', 20)->default('stackable');
            $table->string('exclusivity_group', 60)->nullable();
            $table->boolean('stop_further_processing')->default(false);
            $table->string('calculation_base', 20)->default('current');

            $table->json('conditions')->nullable();
            $table->json('actions');

            $table->boolean('active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->timestamps();

            // Cobre a consulta quente: regras automaticas ativas na ordem de aplicacao.
            $table->index(['trigger', 'active', 'priority'], 'discount_rules_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('discount-engine.tables.rules', 'discount_rules');
    }
};
