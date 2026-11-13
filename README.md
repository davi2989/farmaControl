# 💊 FarmaControl

Sistema web de gerenciamento de farmácia desenvolvido com **HTML, CSS, JavaScript, PHP e MySQL**. Controla produtos, lotes, estoque, compras, vendas, caixa, receitas de medicamentos controlados e relatórios, com interface moderna em tons de verde.

---

## ✨ Funcionalidades

| Módulo | O que faz |
|---|---|
| **Login** | Autenticação com a tabela `funcionario`, senhas com hash (`password_hash`) e sessão segura |
| **Início** | Painel com valor vendido hoje, número de vendas, produtos cadastrados, estoque baixo, lotes a vencer (30 dias) e lotes vencidos |
| **Produtos** | Cadastro, edição, pesquisa por nome ou código de barras, desativação (preserva histórico) e marcação de medicamento controlado |
| **Estoque** | Visão por lote com status (OK, Baixo, Esgotado, Vencido) e **ajuste manual** (perda, vencimento, inventário etc.) |
| **Vendas (PDV)** | Carrinho, busca de produtos, cliente opcional, desconto (gerente/admin) e baixa automática de estoque |
| **Pagamento** | Dinheiro, Pix, débito, crédito e convênio, com cálculo de troco |
| **Compras** | Várias linhas por compra, com lote, fabricação, validade e preço; atualiza estoque e preço de custo |
| **Fornecedores** | Cadastro, edição, pesquisa e exclusão (bloqueada se houver vínculos) |
| **Clientes** | Cadastro com validação de CPF, pesquisa por nome ou CPF e histórico de compras |
| **Funcionários** | Cadastro e edição com controle de login e senha (somente gerente/admin) |
| **Impostos** | Alíquotas associadas aos produtos |
| **Caixa** | Abertura, movimentações, fechamento com valor esperado × contado e diferença, e **cancelamento de venda** |
| **Relatórios** | 8 relatórios, com impressão e exportação para CSV |

### Regras de negócio implementadas

- **FEFO**: a venda sai primeiro do lote com validade mais próxima.
- **Lote vencido não é vendido** nem recebe entrada.
- **Estoque nunca fica negativo.**
- **Medicamento controlado** exige cliente identificado e receita (médico, CRM, data de emissão e validade); a receita é gravada vinculada à venda.
- **Transações** em operações com várias tabelas (venda, compra, ajuste, cancelamento): se algo falhar, nada é gravado.
- Todas as consultas usam **PDO com prepared statements**.
- Descontos, ajustes de estoque, cancelamentos e gestão de funcionários exigem cargo **Gerente** ou **Administrador**.

---

## 🧱 Tecnologias

- **Front-end:** HTML5, CSS3 e JavaScript puro (SPA sem frameworks)
- **Back-end:** PHP 8+ (API JSON em um único arquivo) com PDO
- **Banco de dados:** MySQL / MariaDB (InnoDB, utf8mb4)

```
Navegador (HTML/CSS/JS)  →  api.php (PHP + PDO)  →  MySQL (farmacontrol)
```

O navegador não acessa o banco diretamente: toda operação passa pela API em `api.php`.

---

## 📁 Estrutura

```
farmacontrol/
├── index.html      # Estrutura da página (login + layout)
├── style.css       # Identidade visual
├── app.js          # Telas e lógica do front-end
├── api.php         # API: regras de negócio e acesso ao banco
├── config.php      # Configuração da conexão com o MySQL
├── setup.php       # Cria o usuário admin (usar uma vez e apagar)
└── farmacontrol.sql  # Script do banco de dados
```

---

## 🚀 Instalação (XAMPP / WAMP / Laragon)

### Requisitos
- PHP 8.0 ou superior
- MySQL 5.7+ ou MariaDB 10.3+
- Apache (já incluído no XAMPP)

### Passo a passo

1. **Copie a pasta** do projeto para o diretório web:
   - XAMPP: `C:\xampp\htdocs\farmacontrol`
   - WAMP / Laragon: pasta `www`
2. **Inicie** o Apache e o MySQL no painel do XAMPP.
3. **Importe o banco** no phpMyAdmin (`http://localhost/phpmyadmin`): aba *Importar* → escolha `farmacontrol.sql`.
4. **Configure a conexão** em `config.php` (se o seu MySQL tiver senha):
   ```php
   const DB_HOST = 'localhost';
   const DB_PORT = 3306;
   const DB_NAME = 'farmacontrol';
   const DB_USER = 'root';
   const DB_PASS = '';
   ```
5. **Crie o usuário administrador** abrindo uma vez:
   ```
   http://localhost/farmacontrol/setup.php
   ```
6. **Apague o arquivo `setup.php`.**
7. **Acesse o sistema:**
   ```
   http://localhost/farmacontrol/
   ```

### Primeiro acesso

| Campo | Valor |
|---|---|
| Usuário | `admin` |
| Senha | `admin123` |

> ⚠️ Troque a senha do administrador logo após o primeiro acesso (menu **Funcionários**).

---

## 🧪 Fluxo sugerido para testar

1. Cadastre um **fornecedor** e um **produto**.
2. Registre uma **compra** com número de lote e validade.
3. Abra o **caixa** e faça uma **venda** (o estoque baixa pelo lote que vence primeiro).
4. Teste um **medicamento controlado**: marque um produto como controlado, cadastre um cliente (CPF válido) e venda informando a receita.
5. Em **Caixa**, cancele a venda e veja o estoque voltar; depois **feche o caixa** informando o valor contado.
6. Gere os **relatórios** e use o ajuste manual em **Estoque**.

---

## 🗄️ Banco de dados

Banco `farmacontrol` com 18 tabelas: `fornecedor`, `funcionario`, `cliente`, `produto`, `fornecedor_produto`, `lote`, `estoque`, `medicamento_controlado`, `imposto_produto`, `caixa`, `compra`, `item_compra`, `movimentacao_estoque`, `forma_pagamento`, `venda`, `item_venda`, `receita` e `pagamento`.

O projeto usa a estrutura existente **sem alterá-la**. Detalhes de uso:

- O estoque total por produto fica em `estoque`; o detalhe por lote fica em `lote`.
- O cancelamento de venda grava o motivo em `movimentacao_estoque` e marca `venda.status` como `Cancelada` e `pagamento.status` como `Estornado`.
- O valor esperado no fechamento do caixa é: **valor de abertura + recebimentos em dinheiro** (já descontado o troco).

---

## 🔐 Segurança

- Senhas armazenadas com `password_hash` (bcrypt); nunca em texto puro.
- Consultas com *prepared statements* (proteção contra SQL injection).
- Saída escapada no front-end (proteção contra XSS).
- Permissões por cargo verificadas **no servidor**, não apenas na tela.
- **Não envie o `config.php` com a senha do seu banco para o Git.** Use um `config.php` com valores de exemplo no repositório.
- Em produção, use HTTPS e remova o `setup.php`.

---

## ⚠️ Limitações conhecidas

- O pagamento na tela aceita **uma forma por venda** (a API já aceita várias formas; falta a interface para dividir).
- Vendas só podem ser canceladas enquanto o **caixa da venda estiver aberto**.
- O vínculo fornecedor × produto é criado automaticamente ao registrar uma compra (não há tela de vínculo manual).
- O sistema não emite documento fiscal.
- O controle de medicamentos controlados registra a receita, mas **não substitui** a escrituração e as obrigações da legislação sanitária (por exemplo, SNGPC e Portaria 344/98). Consulte o responsável técnico da farmácia.

---

## 📄 Licença

Defina a licença do seu projeto (por exemplo, MIT) e adicione um arquivo `LICENSE`.
