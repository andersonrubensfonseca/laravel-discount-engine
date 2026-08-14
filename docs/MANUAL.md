# Manual do módulo de descontos

Este manual é para quem vai **cadastrar** descontos, não para quem programa.
Não é preciso saber programar para usar o painel — mas é preciso entender
algumas ideias, e elas estão explicadas aqui.

Leia a seção "Antes de começar" pelo menos uma vez. As demais podem ser
consultadas conforme a necessidade.

---

## Sumário

1. [Antes de começar](#antes-de-começar)
2. [O simulador: use sempre](#o-simulador-use-sempre)
3. [As três decisões de toda regra](#as-três-decisões-de-toda-regra)
4. [Receitas prontas](#receitas-prontas)
5. [Quando o desconto não aparece](#quando-o-desconto-não-aparece)
6. [Armadilhas conhecidas](#armadilhas-conhecidas)
7. [Cancelamento e devolução](#cancelamento-e-devolução)
8. [Glossário](#glossário)

---

## Antes de começar

### Todo valor é em centavos

O sistema não trabalha com vírgula. Multiplique por 100:

| Você quer | Digite |
|---|---|
| R$ 1,99 | `199` |
| R$ 50,00 | `5000` |
| R$ 200,00 | `20000` |

Digitar `200` achando que são duzentos reais cria uma regra de dois reais.
É o erro mais comum e o mais silencioso — a regra funciona, só com o valor
errado.

Percentual é exceção: digite `10` para 10%.

### Uma regra tem duas partes

**Condições** decidem *se* a regra vale. "Subtotal acima de R$ 200",
"primeira compra do cliente". Sem condições, a regra vale sempre.

**Ações** decidem *o que* o desconto faz. "10% no carrinho", "frete grátis",
"a primeira estampa sai a R$ 1,99".

As duas coisas são independentes. A confusão mais comum é tentar usar
condição para limitar *onde* o desconto cai — isso não funciona, e a seção
[Armadilhas](#armadilhas-conhecidas) explica por quê.

### O produto pode ter partes

Uma camisa estampada não tem "um preço": tem o preço da peça mais o da
estamparia. O sistema chama essas partes de **componentes**.

```
Camisa estampada  R$ 55,00
├── base    R$ 40,00   (a peça)
└── print   R$ 15,00   (a estampa)
```

Isso permite dar desconto só na estamparia sem tocar no preço da peça — ou
o contrário.

Os nomes dos componentes (`base`, `print`) são definidos pela equipe técnica.
Confirme com ela quais existem no seu catálogo antes de cadastrar regras que
dependam disso.

---

## O simulador: use sempre

**Menu: Simulador**

Monte um carrinho de mentira, clique em Simular, e veja o que aconteceria.
Nada é gravado, nenhum cliente é afetado.

O simulador mostra três coisas:

1. **O total** com e sem desconto
2. **As regras que entraram** e quanto cada uma deu
3. **As regras que não entraram e o motivo**

A terceira é a mais útil. Quando um desconto não aparece, o simulador diz se
foi a condição, a vigência, o cupom, o limite de uso ou um conflito de grupo.

> **Regra de bolso:** cadastrou, simule. Toda regra nova deveria passar pelo
> simulador antes de ficar ativa. Leva trinta segundos e evita a maioria dos
> problemas descritos neste manual.

### Testando cliente identificado

Algumas condições dependem de saber quem é o cliente. No simulador, marque
**"Cliente identificado"** e ajuste "Pedidos concluídos":

- `0` → simula um cliente na primeira compra
- `5` → simula um cliente recorrente

Sem marcar, o simulador age como visitante não logado.

---

## As três decisões de toda regra

### 1. Como a regra entra

**Automático** — aplica sozinha quando as condições batem. O cliente não
precisa fazer nada.

**Por cupom** — só vale se o cliente digitar o código.

Uma regra por cupom pode ter **vários códigos**, um por linha. Isso serve
para campanhas com código individual por cliente: dez mil códigos, uma regra
só. Os códigos são gravados em caixa alta automaticamente, e o cliente pode
digitar como quiser.

### 2. Se acumula com outros descontos

Esta é a decisão que mais gera confusão. São três mecanismos:

**Modo: Acumulável** (padrão)
A regra convive com as outras. Vários descontos somam no mesmo pedido.

**Modo: Exclusivo**
Se esta regra aplicar, ela é a **única** do pedido. Descarta o que já tinha
sido aplicado antes e bloqueia o que viria depois.

**Grupo**
Regras com o mesmo nome de grupo competem entre si; regras de grupos
diferentes continuam acumulando. Use para "só um desconto de frete por
pedido": coloque todas as regras de frete no grupo `frete`.

Ao usar grupo, escolha quem vence:

| Estratégia | Comportamento |
|---|---|
| A primeira por prioridade | Vence a de menor número em "Prioridade" |
| A que der maior desconto | O sistema calcula todas e aplica a melhor para o cliente |

> ⚠️ **"A primeira por prioridade" ignora o valor.** Se a regra de 5% tem
> prioridade 10 e a de 20% tem prioridade 20, no mesmo grupo, o cliente leva
> **5%** — o pior negócio. Se a intenção é dar o melhor desconto, escolha a
> outra opção.

### 3. Sobre qual valor o percentual incide

**Subtotal já descontado** (padrão) — 10% + 10% resulta em 19%.
A segunda regra incide sobre o que sobrou da primeira.

**Subtotal original** — 10% + 10% resulta em 20%.
As duas incidem sobre o valor cheio.

Para uma regra só, não faz diferença. Para regras que acumulam, muda o
resultado final.

---

## Receitas prontas

Cada receita indica exatamente o que preencher. Campos não mencionados podem
ficar no padrão.

### 10% acima de R$ 200

| Onde | O quê |
|---|---|
| Nome | 10% acima de R$ 200 |
| Como entra | Automático |
| Condição | Subtotal do carrinho · maior ou igual a · `20000` |
| Ação | Desconto percentual · Total do carrinho · valor `10` |

### Cupom de R$ 50, limitado a 100 usos

| Onde | O quê |
|---|---|
| Como entra | Por cupom |
| Códigos | `BEMVINDO50` |
| Limite total | `100` |
| Por cliente | `1` |
| Ação | Desconto de valor fixo · Total do carrinho · valor `5000` |

> "Por cliente: 1" exige cliente identificado. Visitante sem cadastro não
> consegue usar — é proposital, senão a mesma pessoa usaria infinitas vezes.

### Frete grátis acima de R$ 300

| Onde | O quê |
|---|---|
| Condição | Subtotal do carrinho · maior ou igual a · `30000` |
| Ação | Frete grátis · **Frete** |
| Grupo | `frete` |

O grupo garante que só um desconto de frete se aplique, mesmo que outra
campanha de frete esteja no ar.

### 15% na primeira compra

| Onde | O quê |
|---|---|
| Condição | Primeira compra do cliente · igual a · **Sim** |
| Ação | Desconto percentual · Total do carrinho · valor `15` |

> Só funciona com cliente identificado. Se a loja permite comprar sem login,
> o desconto aparece apenas depois que a pessoa entra na conta.

### Leve 3, pague 2

| Onde | O quê |
|---|---|
| Ação | Leve X pague Y · Componentes |
| Tipos de componente | `base` |
| Só nas categorias | ID da categoria (ou vazio para todas) |
| Paga | `2` |
| Leva de graça | `1` |
| Qual sai de graça | O mais barato |

**Como o sistema agrupa:** a cada 3 unidades, 1 sai de graça. Com 7 unidades
são 2 grupos completos e 1 sobrando — 2 brindes. Sobras não geram brinde.

**Qual unidade é a gratuita:** a escolha acontece *dentro de cada grupo de
três*, não no carrinho inteiro. Com peças de R$ 50, 40, 30, 20, 10 e 10, os
grupos ficam (50, 40, 30) e (20, 10, 10) — saem de graça o de R$ 30 e um de
R$ 10, total R$ 40.

### Desconto só na estamparia

| Onde | O quê |
|---|---|
| Ação | Desconto percentual · **Componentes** |
| Tipos de componente | `print` |
| Valor | `20` |

O preço da peça não é tocado. Invertendo para `base`, o desconto vai só na
peça e a estamparia fica cheia.

### Primeira estampa a R$ 1,99 (por camisa)

| Onde | O quê |
|---|---|
| Ação | Preço promocional nas primeiras unidades · **Componentes** |
| Tipos de componente | `print` |
| Só nas categorias | ID das camisas |
| Quantas unidades | `1` |
| Preço promocional | `199` |
| Cota por | **Cada unidade do produto** |

**O campo "Cota por" muda tudo:**

| Opção | 2 camisas com 3 estampas cada |
|---|---|
| Cada unidade do produto | 2 estampas promocionais (uma por camisa) |
| Cada linha do carrinho | 1 estampa promocional |
| Pedido inteiro | 1 estampa promocional |

A regra nunca encarece: se a estampa já custa menos de R$ 1,99, não há
desconto.

### Desconto escalonado por faixa

| Onde | O quê |
|---|---|
| Ação | Desconto escalonado por faixa · Total do carrinho |
| Faixa medida por | Valor (centavos) |
| Faixas | `10000` → 5% · `30000` → 10% · `50000` → 15% |

Vale a **faixa mais alta alcançada**, sem somar entre faixas. Um carrinho de
R$ 600 recebe 15%, não 30%.

Trocando "medida por" para Quantidade, as faixas passam a contar unidades:
5 peças → 5%, 10 peças → 20%.

---

## Quando o desconto não aparece

Siga nesta ordem. O simulador responde os cinco primeiros itens sozinho.

**1. A regra está ativa?**
Na listagem, a coluna Status. Clique para alternar.

**2. A vigência está correta?**
Data de início no futuro ou fim no passado desativa a regra sem aviso.

**3. As condições batem?**
Simule com o carrinho exato. A regra aparecerá em "Regras que não entraram"
com o motivo *"Carrinho não atende as condições"*.

**4. É condição de cliente?**
`Primeira compra` e `Grupo do cliente` exigem cliente identificado. No
simulador, marque a caixa.

**5. Outra regra bloqueou?**
Motivos possíveis: *"Descartada por um desconto exclusivo"*, *"Conflita com
um desconto já aplicado"*, *"Havia uma oferta melhor no mesmo grupo"*.

**6. O tipo de componente está certo?**
Se a ação usa Componentes e o tipo digitado não existe no catálogo, a regra
não encontra nada e fica silenciosa. Confirme os nomes com a equipe técnica.

**7. A categoria está certa?**
Mesmo problema. ID de categoria errado = regra que nunca aplica.

---

## Armadilhas conhecidas

### Condição não limita onde o desconto cai

Esta é a confusão mais cara.

❌ **Errado:** criar a condição "quantidade na categoria Camisas ≥ 1" achando
que o desconto vai cair só nas camisas.

O que acontece: a condição apenas *libera* a regra. Se o carrinho tem camisas
**e** canecas, o desconto cai em tudo.

✅ **Certo:** usar o campo **"Só nas categorias"** dentro da ação.

Resumindo:

| Campo | Função |
|---|---|
| Condições | Decide **se** a regra roda |
| Só nas categorias / Só nestes SKUs | Decide **em quais produtos** |
| Tipos de componente | Decide **em qual parte do preço** |

### Centavos, de novo

Vale repetir porque é o erro mais frequente. `1990` é R$ 19,90.

### Prioridade não é importância

Prioridade é **ordem de aplicação**, não relevância. Menor número aplica
primeiro. Só importa quando regras acumulam ou competem em grupo.

### Cupom usado não some

Ao remover um código da lista de uma regra, se ele já tiver sido usado por
algum cliente, o sistema apenas o **desativa** em vez de apagar. Isso
preserva o histórico dos pedidos. O painel avisa quando isso acontece.

Vale o mesmo para a regra: regra já usada em pedidos não pode ser apagada,
só desativada.

### Desativar não afeta pedidos antigos

Cada pedido guarda uma fotografia do desconto no momento da compra. Editar ou
desativar uma regra hoje **não muda** nenhum pedido já fechado.

---

## Cancelamento e devolução

### Cupom usado não volta

**Esta é a política atual do sistema: uma vez consumido, o cupom não retorna
ao estoque de usos — nem se o pedido for cancelado.**

Um cupom com limite de 100 usos que teve 30 pedidos, sendo 5 cancelados,
continua com 70 disponíveis. Os 5 cancelados seguem contando.

O motivo é evitar fraude: se o uso voltasse, alguém poderia fazer o pedido,
cancelar e repetir indefinidamente, transformando um cupom de uso único em
ilimitado.

**Consequência prática que vale conhecer:** isso vale para qualquer
cancelamento, inclusive os que não são culpa do cliente. Pagamento recusado,
Pix não pago ou boleto vencido também consomem o uso. O cliente que teve o
cartão negado perde o cupom.

Se isso virar um problema recorrente, converse com a equipe técnica — existe
solução, mas depende de como o checkout trata pagamento pendente. Não é algo
que se resolva pelo painel.

### O que fazer quando alguém reclama

**"Meu pedido foi cancelado e o cupom não funciona mais"**
É o comportamento esperado. Se quiser conceder mesmo assim, cadastre um novo
código para aquele cliente — uma regra por cupom aceita vários códigos, então
dá para adicionar um código individual à campanha existente sem criar regra
nova.

**"O cupom esgotou mas tivemos poucos pedidos"**
Provavelmente há pedidos cancelados ou pagamentos não concluídos contando no
total. Peça à equipe técnica o relatório de usos daquele cupom.

**"Preciso liberar mais usos"**
Edite a regra e aumente o "Limite total". O contador de usos já consumidos
não é afetado — se estava em 100/100 e você mudar para 150, ficam 50
disponíveis.

### Devolução parcial

O cliente devolveu um item de três: quanto reembolsar?

O sistema **registra** quanto de desconto coube a cada item do pedido, então
a informação existe. Mas ainda **não há tela** para consultar isso — hoje
depende da equipe técnica.

Um ponto que não tem resposta automática: em "Leve 3, pague 2", se o cliente
devolve uma peça, ela era a gratuita ou uma das pagas? Isso é decisão
comercial, não cálculo. Vale definir a política antes de a primeira devolução
acontecer.

---

## Glossário

| Termo | Significado |
|---|---|
| **Subtotal** | Soma dos produtos, sem frete |
| **Componente** | Uma parte do preço de um produto (peça, estampa, bordado) |
| **Condição** | Requisito que o carrinho precisa atender |
| **Ação** | O que o desconto faz |
| **Alvo** | Onde a ação incide: carrinho, itens, componentes ou frete |
| **Prioridade** | Ordem de aplicação; menor aplica primeiro |
| **Grupo** | Conjunto de regras que competem entre si |
| **Exclusivo** | Regra que descarta todos os outros descontos do pedido |
| **Vigência** | Período em que a regra vale |
| **Cota** | Quantas unidades recebem o preço promocional |

---

## Roteiro para se familiarizar

Faça na ordem, tudo no simulador. Leva uns quinze minutos e cobre os
conceitos principais.

**1.** Crie "10% acima de R$ 200". Simule com carrinho de R$ 150 (não deve
aplicar) e R$ 250 (deve dar R$ 25). Leia a mensagem de rejeição no primeiro
caso.

**2.** Mude a condição para R$ 100 e simule de novo com R$ 150. Veja o
desconto aparecer.

**3.** Crie uma segunda regra de 10%, também automática. Simule: as duas
acumulam e o total dá 19%, não 20%. Troque a base de cálculo da segunda para
"Subtotal original" e veja virar 20%.

**4.** Coloque as duas no grupo `teste` com "A primeira por prioridade".
Simule: só uma aplica. Troque para "A que der maior desconto" e compare.

**5.** Marque a primeira como Exclusivo. Simule: ela descarta a outra
completamente.

**6.** Crie um cupom com limite 1. Simule com e sem o código digitado.

**7.** Se o catálogo tem produtos compostos, monte uma camisa com componentes
`base` e `print` no simulador e crie a regra da primeira estampa a R$ 1,99.
Aumente a quantidade da camisa para 2 e veja o desconto dobrar.

Ao final, desative ou apague as regras de teste.
