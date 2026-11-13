<?php
require 'config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

class Falha extends Exception { public $c; function __construct($m, $c = 400) { parent::__construct($m); $this->c = $c; } }
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function q($sql, $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function all($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function one($sql, $p = []) { return q($sql, $p)->fetch() ?: null; }
function tx(callable $f) {
    global $pdo; $pdo->beginTransaction();
    try { $r = $f(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

try { $pdo = conectar(); } catch (Throwable $e) { out(['erro' => 'Não foi possível conectar ao MySQL. Verifique o config.php.'], 500); }

$rota = '/' . trim($_GET['r'] ?? '', '/');
$m = $_SERVER['REQUEST_METHOD'];
$b = json_decode(file_get_contents('php://input'), true) ?: [];
$u = $_SESSION['user'] ?? null;

try {
    if ($rota === '/login' && $m === 'POST') {
        $f = one('SELECT * FROM funcionario WHERE login=?', [$b['login'] ?? '']);
        if (empty($b['login']) || empty($b['senha'])) throw new Falha('Informe usuário e senha.');
        if (!$f || !$f['senha'] || !password_verify($b['senha'], $f['senha'])) throw new Falha('Usuário ou senha inválidos.', 401);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$f['id_funcionario'], 'nome' => $f['nome'], 'cargo' => $f['cargo'] ?? ''];
        out(['user' => $_SESSION['user']]);
    }
    if ($rota === '/logout') { session_destroy(); out(['ok' => true]); }
    if (!$u) throw new Falha('Sessão expirada. Faça login novamente.', 401);

    // ---------- DASHBOARD ----------
    if ($rota === '/dashboard') {
        $v = one("SELECT COUNT(*) qtd, COALESCE(SUM(valor_total),0) total FROM venda WHERE DATE(data_venda)=CURDATE() AND status='Concluida'");
        out([
            'vendasHoje' => (int)$v['qtd'], 'totalHoje' => (float)$v['total'],
            'produtos' => (int)one('SELECT COUNT(*) n FROM produto WHERE ativo=1')['n'],
            'estoqueBaixo' => (int)one('SELECT COUNT(*) n FROM estoque e JOIN produto p ON p.id_produto=e.id_produto WHERE p.ativo=1 AND e.quantidade_atual<=e.quantidade_minima')['n'],
            'aVencer' => (int)one('SELECT COUNT(*) n FROM lote WHERE quantidade_atual>0 AND data_validade BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)')['n'],
            'vencidos' => (int)one('SELECT COUNT(*) n FROM lote WHERE quantidade_atual>0 AND data_validade<CURDATE()')['n'],
        ]);
    }

    // ---------- PRODUTOS ----------
    $prodSQL = "SELECT p.*, COALESCE(e.quantidade_atual,0) estoque, mc.tipo_controle, COALESCE(mc.exige_receita,0) controlado
        FROM produto p LEFT JOIN estoque e ON e.id_produto=p.id_produto
        LEFT JOIN medicamento_controlado mc ON mc.id_produto=p.id_produto";
    $validar = function ($b) {
        if (trim($b['nome'] ?? '') === '') throw new Falha('Nome do produto é obrigatório.');
        if (!((float)($b['preco_venda'] ?? 0) > 0)) throw new Falha('Preço de venda deve ser maior que zero.');
        if ((float)($b['preco_custo'] ?? 0) < 0 || (int)($b['estoque_minimo'] ?? 0) < 0) throw new Falha('Valores não podem ser negativos.');
    };
    $controlado = function ($id, $b) {
        if (!empty($b['controlado']))
            q('INSERT INTO medicamento_controlado (id_produto,tipo_controle,exige_receita) VALUES (?,?,1)
               ON DUPLICATE KEY UPDATE tipo_controle=VALUES(tipo_controle), exige_receita=1', [$id, $b['tipo_controle'] ?: 'Controlado']);
        else q('DELETE FROM medicamento_controlado WHERE id_produto=?', [$id]);
    };

    if ($rota === '/produtos' && $m === 'GET') {
        $t = '%' . ($_GET['q'] ?? '') . '%';
        out(all($prodSQL . ' WHERE p.ativo=1 AND (p.nome LIKE ? OR p.codigo_barras LIKE ?) ORDER BY p.nome LIMIT 200', [$t, $t]));
    }
    if ($rota === '/produtos' && $m === 'POST') {
        $validar($b); $ini = (int)($b['estoque_inicial'] ?? 0);
        if ($ini > 0 && (empty($b['numero_lote']) || empty($b['data_validade']))) throw new Falha('Estoque inicial exige número do lote e validade.');
        $id = tx(function () use ($b, $ini, $u, $controlado) {
            global $pdo;
            q('INSERT INTO produto (nome,categoria,codigo_barras,preco_custo,preco_venda,estoque_minimo,unidade_medida) VALUES (?,?,?,?,?,?,?)',
              [trim($b['nome']), $b['categoria'] ?: null, ($b['codigo_barras'] ?? '') ?: null, $b['preco_custo'] ?: 0, $b['preco_venda'], $b['estoque_minimo'] ?: 0, 'UN']);
            $id = (int)$pdo->lastInsertId();
            q('INSERT INTO estoque (id_produto,quantidade_atual,quantidade_minima) VALUES (?,?,?)', [$id, $ini, $b['estoque_minimo'] ?: 0]);
            if ($ini > 0) {
                q('INSERT INTO lote (id_produto,numero_lote,data_validade,quantidade_inicial,quantidade_atual) VALUES (?,?,?,?,?)', [$id, $b['numero_lote'], $b['data_validade'], $ini, $ini]);
                q("INSERT INTO movimentacao_estoque (id_produto,id_lote,id_funcionario,tipo_movimentacao,quantidade,motivo) VALUES (?,?,?,'Entrada',?,'Estoque inicial')",
                  [$id, $pdo->lastInsertId(), $u['id'], $ini]);
            }
            $controlado($id, $b);
            return $id;
        });
        out(['id' => $id]);
    }
    if (preg_match('#^/produtos/(\d+)$#', $rota, $mt)) {
        $id = (int)$mt[1];
        if ($m === 'PUT') {
            $validar($b);
            tx(function () use ($b, $id, $controlado) {
                q('UPDATE produto SET nome=?,categoria=?,codigo_barras=?,preco_custo=?,preco_venda=?,estoque_minimo=? WHERE id_produto=?',
                  [trim($b['nome']), $b['categoria'] ?: null, ($b['codigo_barras'] ?? '') ?: null, $b['preco_custo'] ?: 0, $b['preco_venda'], $b['estoque_minimo'] ?: 0, $id]);
                q('UPDATE estoque SET quantidade_minima=? WHERE id_produto=?', [$b['estoque_minimo'] ?: 0, $id]);
                $controlado($id, $b);
            });
            out(['ok' => true]);
        }
        if ($m === 'DELETE') { q('UPDATE produto SET ativo=0 WHERE id_produto=?', [$id]); out(['ok' => true]); }
    }

    // ---------- ESTOQUE ----------
    if ($rota === '/estoque') {
        $lotes = all("SELECT l.id_lote, p.nome produto, l.numero_lote lote, l.data_validade validade, l.quantidade_atual quantidade, p.estoque_minimo minimo,
            CASE WHEN l.data_validade<CURDATE() THEN 'Vencido' WHEN l.quantidade_atual=0 THEN 'Esgotado'
                 WHEN l.quantidade_atual<=p.estoque_minimo THEN 'Baixo' ELSE 'OK' END status
            FROM lote l JOIN produto p ON p.id_produto=l.id_produto WHERE p.ativo=1 ORDER BY l.data_validade LIMIT 500");
        $t = one('SELECT COUNT(*) total, COALESCE(SUM(e.quantidade_atual>e.quantidade_minima),0) ok, COALESCE(SUM(e.quantidade_atual<=e.quantidade_minima),0) baixo
            FROM estoque e JOIN produto p ON p.id_produto=e.id_produto WHERE p.ativo=1');
        out(['lotes' => $lotes, 'total' => (int)$t['total'], 'ok' => (int)$t['ok'], 'baixo' => (int)$t['baixo'],
             'vencidos' => (int)one('SELECT COUNT(*) n FROM lote WHERE quantidade_atual>0 AND data_validade<CURDATE()')['n']]);
    }

    // ---------- CLIENTES / PAGAMENTO / CAIXA ----------
    if ($rota === '/clientes' && $m === 'GET') {
        $t = '%' . ($_GET['q'] ?? '') . '%';
        out(isset($_GET['full'])
            ? all('SELECT * FROM cliente WHERE nome LIKE ? OR cpf LIKE ? ORDER BY nome LIMIT 200', [$t, $t])
            : all('SELECT id_cliente,nome,cpf FROM cliente WHERE nome LIKE ? OR cpf LIKE ? ORDER BY nome LIMIT 10', [$t, $t]));
    }
    if ($rota === '/formas-pagamento') out(all('SELECT * FROM forma_pagamento ORDER BY id_forma_pagamento'));
    if ($rota === '/caixa/atual') out(one("SELECT * FROM caixa WHERE id_funcionario=? AND status='Aberto' ORDER BY id_caixa DESC LIMIT 1", [$u['id']]));
    if ($rota === '/caixa/abrir' && $m === 'POST') {
        $v = $b['valor'] ?? '';
        if (!is_numeric($v) || $v < 0) throw new Falha('Valor inicial inválido.');
        if (one("SELECT id_caixa FROM caixa WHERE id_funcionario=? AND status='Aberto'", [$u['id']])) throw new Falha('Você já possui um caixa aberto.');
        q('INSERT INTO caixa (id_funcionario,valor_abertura) VALUES (?,?)', [$u['id'], $v]);
        out(['ok' => true]);
    }

    // ---------- VENDAS ----------
    if ($rota === '/vendas' && $m === 'POST') {
        $itens = $b['itens'] ?? []; $pags = $b['pagamentos'] ?? []; $rec = $b['receita'] ?? null;
        $idc = $b['id_cliente'] ?? null; $desc = (float)($b['desconto'] ?? 0);
        if (!$itens) throw new Falha('Carrinho vazio.');
        if (!$pags) throw new Falha('Informe a forma de pagamento.');
        if ($desc < 0) throw new Falha('Desconto inválido.');
        if ($desc > 0 && !preg_match('/admin|gerente/i', $u['cargo'])) throw new Falha('Desconto só pode ser aplicado por gerente/administrador.', 403);

        $res = tx(function () use ($itens, $pags, $rec, $idc, $desc, $u) {
            global $pdo;
            $cx = one("SELECT id_caixa FROM caixa WHERE id_funcionario=? AND status='Aberto' LIMIT 1", [$u['id']]);
            if (!$cx) throw new Falha('Abra o caixa antes de vender.');
            $bruto = 0; $precisa = false; $linhas = [];
            foreach ($itens as $it) {
                $qtd = (int)($it['quantidade'] ?? 0);
                if ($qtd <= 0) throw new Falha('Quantidade inválida.');
                $p = one('SELECT p.*, mc.exige_receita FROM produto p LEFT JOIN medicamento_controlado mc ON mc.id_produto=p.id_produto
                          WHERE p.id_produto=? AND p.ativo=1 FOR UPDATE', [$it['id_produto']]);
                if (!$p) throw new Falha('Produto não encontrado ou inativo.');
                if ($p['exige_receita']) $precisa = true;
                // FEFO: vencimento mais próximo primeiro, ignorando lotes vencidos
                $lotes = all('SELECT id_lote,quantidade_atual FROM lote WHERE id_produto=? AND quantidade_atual>0 AND data_validade>=CURDATE() ORDER BY data_validade FOR UPDATE', [$p['id_produto']]);
                $falta = $qtd;
                foreach ($lotes as $l) {
                    if (!$falta) break;
                    $usar = min($falta, (int)$l['quantidade_atual']);
                    $linhas[] = ['p' => $p, 'lote' => $l['id_lote'], 'qtd' => $usar]; $falta -= $usar;
                }
                if ($falta) throw new Falha('Estoque insuficiente (ou lotes vencidos) para "' . $p['nome'] . '".');
                $bruto += (float)$p['preco_venda'] * $qtd;
            }
            if ($desc > $bruto) throw new Falha('Desconto maior que o total.');
            $total = round($bruto - $desc, 2);

            if ($precisa) {
                if (!$idc) throw new Falha('Medicamento controlado exige cliente identificado.');
                if (!$rec || empty($rec['nome_medico']) || empty($rec['crm']) || empty($rec['data_emissao'])) throw new Falha('Medicamento controlado exige receita (médico, CRM e data de emissão).');
                if (!empty($rec['data_validade']) && $rec['data_validade'] < date('Y-m-d')) throw new Falha('Receita vencida.');
            }

            $pago = 0; $temDinheiro = false;
            foreach ($pags as &$pg) {
                $f = one('SELECT descricao FROM forma_pagamento WHERE id_forma_pagamento=?', [$pg['id_forma_pagamento'] ?? 0]);
                if (!$f) throw new Falha('Forma de pagamento inválida.');
                $pg['dinheiro'] = $f['descricao'] === 'Dinheiro'; $temDinheiro = $temDinheiro || $pg['dinheiro'];
                $pago += (float)$pg['valor'];
            } unset($pg);
            if ($pago + 0.001 < $total) throw new Falha('Valor pago é menor que o total.');
            $troco = round($pago - $total, 2);
            if ($troco > 0 && !$temDinheiro) throw new Falha('Troco só é permitido em pagamento em dinheiro.');

            q('INSERT INTO venda (id_cliente,id_funcionario,id_caixa,valor_total,desconto) VALUES (?,?,?,?,?)', [$idc ?: null, $u['id'], $cx['id_caixa'], $total, $desc]);
            $idv = (int)$pdo->lastInsertId();
            foreach ($linhas as $l) {
                $sub = round((float)$l['p']['preco_venda'] * $l['qtd'], 2);
                q('INSERT INTO item_venda (id_venda,id_produto,id_lote,quantidade,preco_unitario,subtotal) VALUES (?,?,?,?,?,?)', [$idv, $l['p']['id_produto'], $l['lote'], $l['qtd'], $l['p']['preco_venda'], $sub]);
                q('UPDATE lote SET quantidade_atual=quantidade_atual-? WHERE id_lote=? AND quantidade_atual>=?', [$l['qtd'], $l['lote'], $l['qtd']]);
                $up = q('UPDATE estoque SET quantidade_atual=quantidade_atual-? WHERE id_produto=? AND quantidade_atual>=?', [$l['qtd'], $l['p']['id_produto'], $l['qtd']]);
                if (!$up->rowCount()) throw new Falha('Estoque negativo não permitido: "' . $l['p']['nome'] . '".');
                q("INSERT INTO movimentacao_estoque (id_produto,id_lote,id_funcionario,tipo_movimentacao,quantidade,motivo) VALUES (?,?,?,'Saida',?,?)", [$l['p']['id_produto'], $l['lote'], $u['id'], $l['qtd'], "Venda #$idv"]);
            }
            foreach ($pags as $pg) {
                $val = (float)$pg['valor'];
                if ($pg['dinheiro'] && $troco > 0) { $d = min($troco, $val); $val -= $d; $troco -= $d; }
                q('INSERT INTO pagamento (id_venda,id_forma_pagamento,valor) VALUES (?,?,?)', [$idv, $pg['id_forma_pagamento'], number_format($val, 2, '.', '')]);
            }
            if ($precisa) q('INSERT INTO receita (id_cliente,id_funcionario,id_venda,nome_medico,crm,data_emissao,data_validade) VALUES (?,?,?,?,?,?,?)',
                [$idc, $u['id'], $idv, $rec['nome_medico'], $rec['crm'], $rec['data_emissao'], $rec['data_validade'] ?: null]);
            return ['id_venda' => $idv, 'total' => $total];
        });
        out($res);
    }
    // ---------- FORNECEDORES ----------
    $validarForn = function ($b) {
        if (trim($b['nome'] ?? '') === '') throw new Falha('Nome do fornecedor é obrigatório.');
        if (!empty($b['email']) && !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) throw new Falha('E-mail inválido.');
    };
    if ($rota === '/fornecedores' && $m === 'GET') {
        $t = '%' . ($_GET['q'] ?? '') . '%';
        out(all('SELECT * FROM fornecedor WHERE nome LIKE ? OR cnpj LIKE ? ORDER BY nome LIMIT 200', [$t, $t]));
    }
    if ($rota === '/fornecedores' && $m === 'POST') {
        $validarForn($b);
        q('INSERT INTO fornecedor (nome,cnpj,telefone,endereco,email) VALUES (?,?,?,?,?)',
          [trim($b['nome']), ($b['cnpj'] ?? '') ?: null, $b['telefone'] ?? null, $b['endereco'] ?? null, ($b['email'] ?? '') ?: null]);
        out(['ok' => true]);
    }
    if (preg_match('#^/fornecedores/(\d+)$#', $rota, $mt)) {
        if ($m === 'PUT') {
            $validarForn($b);
            q('UPDATE fornecedor SET nome=?,cnpj=?,telefone=?,endereco=?,email=? WHERE id_fornecedor=?',
              [trim($b['nome']), ($b['cnpj'] ?? '') ?: null, $b['telefone'] ?? null, $b['endereco'] ?? null, ($b['email'] ?? '') ?: null, $mt[1]]);
            out(['ok' => true]);
        }
        if ($m === 'DELETE') { q('DELETE FROM fornecedor WHERE id_fornecedor=?', [$mt[1]]); out(['ok' => true]); }
    }

    // ---------- COMPRAS ----------
    if ($rota === '/compras' && $m === 'GET') {
        out(all('SELECT c.id_compra,c.data_compra,c.valor_total,c.status,f.nome fornecedor,fu.nome funcionario
            FROM compra c JOIN fornecedor f ON f.id_fornecedor=c.id_fornecedor
            JOIN funcionario fu ON fu.id_funcionario=c.id_funcionario ORDER BY c.id_compra DESC LIMIT 200'));
    }
    if ($rota === '/compras' && $m === 'POST') {
        $idf = (int)($b['id_fornecedor'] ?? 0); $itens = $b['itens'] ?? [];
        if (!$idf || !one('SELECT 1 x FROM fornecedor WHERE id_fornecedor=?', [$idf])) throw new Falha('Selecione um fornecedor.');
        if (!$itens) throw new Falha('Adicione ao menos um item.');
        $res = tx(function () use ($idf, $itens, $u) {
            global $pdo;
            $total = 0;
            foreach ($itens as $it) {
                $qtd = (int)($it['quantidade'] ?? 0); $pr = (float)($it['preco_unitario'] ?? -1);
                $val = $it['data_validade'] ?? ''; $fab = $it['data_fabricacao'] ?? '';
                if (!one('SELECT 1 x FROM produto WHERE id_produto=? AND ativo=1', [$it['id_produto'] ?? 0])) throw new Falha('Produto inválido na compra.');
                if ($qtd <= 0) throw new Falha('Quantidade deve ser maior que zero.');
                if ($pr < 0) throw new Falha('Preço unitário inválido.');
                if (trim($it['numero_lote'] ?? '') === '') throw new Falha('Informe o número do lote de cada item.');
                if (!$val || $val < date('Y-m-d')) throw new Falha('Validade inválida: não é possível receber lote vencido.');
                if ($fab && $fab > $val) throw new Falha('Data de fabricação posterior à validade.');
                $total += round($qtd * $pr, 2);
            }
            q('INSERT INTO compra (id_fornecedor,id_funcionario,valor_total) VALUES (?,?,?)', [$idf, $u['id'], $total]);
            $idc = (int)$pdo->lastInsertId();
            foreach ($itens as $it) {
                $pid = (int)$it['id_produto']; $qtd = (int)$it['quantidade']; $pr = (float)$it['preco_unitario']; $nl = trim($it['numero_lote']);
                $lote = one('SELECT id_lote,data_validade FROM lote WHERE id_produto=? AND numero_lote=? FOR UPDATE', [$pid, $nl]);
                if ($lote) {
                    if ($lote['data_validade'] !== $it['data_validade']) throw new Falha("O lote $nl já existe com outra validade.");
                    $idl = (int)$lote['id_lote'];
                    q('UPDATE lote SET quantidade_inicial=quantidade_inicial+?, quantidade_atual=quantidade_atual+? WHERE id_lote=?', [$qtd, $qtd, $idl]);
                } else {
                    q('INSERT INTO lote (id_produto,id_fornecedor,numero_lote,data_fabricacao,data_validade,quantidade_inicial,quantidade_atual) VALUES (?,?,?,?,?,?,?)',
                      [$pid, $idf, $nl, ($it['data_fabricacao'] ?? '') ?: null, $it['data_validade'], $qtd, $qtd]);
                    $idl = (int)$pdo->lastInsertId();
                }
                q('INSERT INTO item_compra (id_compra,id_produto,id_lote,quantidade,preco_unitario,subtotal) VALUES (?,?,?,?,?,?)',
                  [$idc, $pid, $idl, $qtd, $pr, round($qtd * $pr, 2)]);
                $up = q('UPDATE estoque SET quantidade_atual=quantidade_atual+? WHERE id_produto=?', [$qtd, $pid]);
                if (!$up->rowCount()) q('INSERT INTO estoque (id_produto,quantidade_atual,quantidade_minima) SELECT id_produto,?,estoque_minimo FROM produto WHERE id_produto=?', [$qtd, $pid]);
                q("INSERT INTO movimentacao_estoque (id_produto,id_lote,id_funcionario,tipo_movimentacao,quantidade,motivo) VALUES (?,?,?,'Entrada',?,?)", [$pid, $idl, $u['id'], $qtd, "Compra #$idc"]);
                q('INSERT INTO fornecedor_produto (id_fornecedor,id_produto,preco_fornecimento) VALUES (?,?,?) ON DUPLICATE KEY UPDATE preco_fornecimento=VALUES(preco_fornecimento)', [$idf, $pid, $pr]);
                q('UPDATE produto SET preco_custo=? WHERE id_produto=?', [$pr, $pid]);
            }
            return ['id_compra' => $idc, 'total' => $total];
        });
        out($res);
    }

    // ---------- VALIDAÇÕES ----------
    $cpfOk = function ($c) {
        $c = preg_replace('/\D/', '', $c);
        if (strlen($c) != 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $i = 0; $i < $t; $i++) $d += $c[$i] * (($t + 1) - $i);
            if ($c[$t] != ((10 * $d) % 11) % 10) return false;
        }
        return true;
    };
    $cpfFmt = function ($c) {
        $c = preg_replace('/\D/', '', $c ?? '');
        return $c === '' ? null : substr($c, 0, 3) . '.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-' . substr($c, 9, 2);
    };
    $checarPessoa = function ($b) use ($cpfOk) {
        if (trim($b['nome'] ?? '') === '') throw new Falha('Nome é obrigatório.');
        if (!empty($b['cpf']) && !$cpfOk($b['cpf'])) throw new Falha('CPF inválido.');
        if (!empty($b['email']) && !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) throw new Falha('E-mail inválido.');
    };
    $nul = fn($v) => (isset($v) && $v !== '') ? $v : null;

    // ---------- CLIENTES ----------
    if (preg_match('#^/clientes/(\d+)/historico$#', $rota, $mt)) {
        out(all("SELECT v.id_venda,v.data_venda,v.valor_total,v.status,
            (SELECT GROUP_CONCAT(CONCAT(p.nome,' x',iv.quantidade) SEPARATOR ', ') FROM item_venda iv JOIN produto p ON p.id_produto=iv.id_produto WHERE iv.id_venda=v.id_venda) itens
            FROM venda v WHERE v.id_cliente=? ORDER BY v.id_venda DESC LIMIT 100", [$mt[1]]));
    }
    if ($rota === '/clientes' && $m === 'POST') {
        $checarPessoa($b);
        if (!empty($b['data_nascimento']) && $b['data_nascimento'] > date('Y-m-d')) throw new Falha('Data de nascimento inválida.');
        q('INSERT INTO cliente (nome,cpf,telefone,email,endereco,data_nascimento) VALUES (?,?,?,?,?,?)',
          [trim($b['nome']), $cpfFmt($b['cpf'] ?? ''), $nul($b['telefone'] ?? null), $nul($b['email'] ?? null), $nul($b['endereco'] ?? null), $nul($b['data_nascimento'] ?? null)]);
        out(['ok' => true]);
    }
    if (preg_match('#^/clientes/(\d+)$#', $rota, $mt)) {
        if ($m === 'PUT') {
            $checarPessoa($b);
            q('UPDATE cliente SET nome=?,cpf=?,telefone=?,email=?,endereco=?,data_nascimento=? WHERE id_cliente=?',
              [trim($b['nome']), $cpfFmt($b['cpf'] ?? ''), $nul($b['telefone'] ?? null), $nul($b['email'] ?? null), $nul($b['endereco'] ?? null), $nul($b['data_nascimento'] ?? null), $mt[1]]);
            out(['ok' => true]);
        }
        if ($m === 'DELETE') { q('DELETE FROM cliente WHERE id_cliente=?', [$mt[1]]); out(['ok' => true]); }
    }

    // ---------- FUNCIONÁRIOS (somente gerente/admin) ----------
    if (strpos($rota, '/funcionarios') === 0) {
        if (!preg_match('/admin|gerente/i', $u['cargo'])) throw new Falha('Apenas gerente ou administrador pode gerenciar funcionários.', 403);
        if ($rota === '/funcionarios' && $m === 'GET') {
            $t = '%' . ($_GET['q'] ?? '') . '%';
            out(all('SELECT id_funcionario,nome,cpf,telefone,email,cargo,login,data_admissao,salario FROM funcionario WHERE nome LIKE ? OR login LIKE ? ORDER BY nome', [$t, $t]));
        }
        if ($rota === '/funcionarios' && $m === 'POST') {
            $checarPessoa($b);
            if (trim($b['login'] ?? '') === '') throw new Falha('Login é obrigatório.');
            if (strlen($b['senha'] ?? '') < 6) throw new Falha('A senha deve ter ao menos 6 caracteres.');
            q('INSERT INTO funcionario (nome,cpf,telefone,email,cargo,login,senha,data_admissao,salario) VALUES (?,?,?,?,?,?,?,?,?)',
              [trim($b['nome']), $cpfFmt($b['cpf'] ?? ''), $nul($b['telefone'] ?? null), $nul($b['email'] ?? null), $nul($b['cargo'] ?? null), trim($b['login']),
               password_hash($b['senha'], PASSWORD_DEFAULT), $nul($b['data_admissao'] ?? null), $nul($b['salario'] ?? null)]);
            out(['ok' => true]);
        }
        if (preg_match('#^/funcionarios/(\d+)$#', $rota, $mt) && $m === 'PUT') {
            $checarPessoa($b);
            if (trim($b['login'] ?? '') === '') throw new Falha('Login é obrigatório.');
            tx(function () use ($b, $mt, $cpfFmt, $nul) {
                q('UPDATE funcionario SET nome=?,cpf=?,telefone=?,email=?,cargo=?,login=?,data_admissao=?,salario=? WHERE id_funcionario=?',
                  [trim($b['nome']), $cpfFmt($b['cpf'] ?? ''), $nul($b['telefone'] ?? null), $nul($b['email'] ?? null), $nul($b['cargo'] ?? null), trim($b['login']),
                   $nul($b['data_admissao'] ?? null), $nul($b['salario'] ?? null), $mt[1]]);
                if (!empty($b['senha'])) {
                    if (strlen($b['senha']) < 6) throw new Falha('A senha deve ter ao menos 6 caracteres.');
                    q('UPDATE funcionario SET senha=? WHERE id_funcionario=?', [password_hash($b['senha'], PASSWORD_DEFAULT), $mt[1]]);
                }
            });
            out(['ok' => true]);
        }
    }

    // ---------- IMPOSTOS ----------
    $checarImp = function ($b) {
        if (!one('SELECT 1 x FROM produto WHERE id_produto=?', [$b['id_produto'] ?? 0])) throw new Falha('Selecione um produto.');
        if (trim($b['nome_imposto'] ?? '') === '') throw new Falha('Informe o nome do imposto.');
        if (!is_numeric($b['aliquota'] ?? '') || $b['aliquota'] < 0 || $b['aliquota'] > 100) throw new Falha('Alíquota deve estar entre 0 e 100.');
    };
    if ($rota === '/impostos' && $m === 'GET') {
        $t = '%' . ($_GET['q'] ?? '') . '%';
        out(all('SELECT i.*, p.nome produto FROM imposto_produto i JOIN produto p ON p.id_produto=i.id_produto WHERE p.nome LIKE ? OR i.nome_imposto LIKE ? ORDER BY p.nome, i.nome_imposto', [$t, $t]));
    }
    if ($rota === '/impostos' && $m === 'POST') {
        $checarImp($b);
        q('INSERT INTO imposto_produto (id_produto,nome_imposto,aliquota) VALUES (?,?,?)', [$b['id_produto'], trim($b['nome_imposto']), $b['aliquota']]);
        out(['ok' => true]);
    }
    if (preg_match('#^/impostos/(\d+)$#', $rota, $mt)) {
        if ($m === 'PUT') { $checarImp($b); q('UPDATE imposto_produto SET id_produto=?,nome_imposto=?,aliquota=? WHERE id_imposto=?', [$b['id_produto'], trim($b['nome_imposto']), $b['aliquota'], $mt[1]]); out(['ok' => true]); }
        if ($m === 'DELETE') { q('DELETE FROM imposto_produto WHERE id_imposto=?', [$mt[1]]); out(['ok' => true]); }
    }

    // ---------- FECHAMENTO DE CAIXA ----------
    $resumoCaixa = function ($cx) {
        $id = $cx['id_caixa'];
        $v = one("SELECT COUNT(*) qtd, COALESCE(SUM(valor_total),0) total FROM venda WHERE id_caixa=? AND status='Concluida'", [$id]);
        $formas = all("SELECT fp.descricao forma, COALESCE(SUM(pg.valor),0) total FROM pagamento pg
            JOIN forma_pagamento fp ON fp.id_forma_pagamento=pg.id_forma_pagamento JOIN venda v ON v.id_venda=pg.id_venda
            WHERE v.id_caixa=? AND v.status='Concluida' AND pg.status='Confirmado' GROUP BY fp.descricao", [$id]);
        $din = 0.0; foreach ($formas as $f) if ($f['forma'] === 'Dinheiro') $din = (float)$f['total'];
        $vendas = all("SELECT v.id_venda,v.data_venda,v.valor_total,COALESCE(c.nome,'-') cliente FROM venda v LEFT JOIN cliente c ON c.id_cliente=v.id_cliente
            WHERE v.id_caixa=? AND v.status='Concluida' ORDER BY v.id_venda DESC LIMIT 200", [$id]);
        return ['caixa' => $cx, 'qtd' => (int)$v['qtd'], 'total' => (float)$v['total'], 'formas' => $formas, 'dinheiro' => $din,
                'esperado' => round((float)$cx['valor_abertura'] + $din, 2), 'vendas' => $vendas];
    };
    if ($rota === '/caixa/resumo') {
        $cx = one("SELECT * FROM caixa WHERE id_funcionario=? AND status='Aberto' ORDER BY id_caixa DESC LIMIT 1", [$u['id']]);
        out($cx ? $resumoCaixa($cx) : null);
    }
    if ($rota === '/caixa/fechar' && $m === 'POST') {
        $v = $b['valor'] ?? '';
        if (!is_numeric($v) || $v < 0) throw new Falha('Valor contado inválido.');
        $res = tx(function () use ($u, $v, $resumoCaixa) {
            $cx = one("SELECT * FROM caixa WHERE id_funcionario=? AND status='Aberto' ORDER BY id_caixa DESC LIMIT 1 FOR UPDATE", [$u['id']]);
            if (!$cx) throw new Falha('Nenhum caixa aberto.');
            $r = $resumoCaixa($cx);
            q("UPDATE caixa SET status='Fechado', data_fechamento=NOW(), valor_fechamento=? WHERE id_caixa=?", [$v, $cx['id_caixa']]);
            return ['esperado' => $r['esperado'], 'informado' => (float)$v, 'diferenca' => round((float)$v - $r['esperado'], 2)];
        });
        out($res);
    }

    // ---------- RELATÓRIOS ----------
    if (preg_match('#^/relatorios/(\w+)$#', $rota, $mt)) {
        $de = $_GET['de'] ?? date('Y-m-01'); $ate = $_GET['ate'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) || $de > $ate) throw new Falha('Período inválido.');
        $R = [
          'vendas' => [1, "SELECT v.id_venda `Venda`, v.data_venda `Data`, COALESCE(c.nome,'-') `Cliente`, f.nome `Funcionário`, v.desconto `Desconto (R$)`, v.valor_total `Total (R$)`
              FROM venda v LEFT JOIN cliente c ON c.id_cliente=v.id_cliente JOIN funcionario f ON f.id_funcionario=v.id_funcionario
              WHERE DATE(v.data_venda) BETWEEN ? AND ? AND v.status='Concluida' ORDER BY v.data_venda DESC"],
          'mais_vendidos' => [1, "SELECT p.nome `Produto`, SUM(iv.quantidade) `Quantidade`, SUM(iv.subtotal) `Total (R$)`
              FROM item_venda iv JOIN venda v ON v.id_venda=iv.id_venda JOIN produto p ON p.id_produto=iv.id_produto
              WHERE DATE(v.data_venda) BETWEEN ? AND ? AND v.status='Concluida' GROUP BY p.id_produto, p.nome ORDER BY SUM(iv.quantidade) DESC LIMIT 50"],
          'estoque' => [0, "SELECT p.nome `Produto`, p.categoria `Categoria`, COALESCE(e.quantidade_atual,0) `Quantidade`, p.estoque_minimo `Mínimo`, p.preco_venda `Preço venda (R$)`
              FROM produto p LEFT JOIN estoque e ON e.id_produto=p.id_produto WHERE p.ativo=1 ORDER BY p.nome"],
          'estoque_baixo' => [0, "SELECT p.nome `Produto`, COALESCE(e.quantidade_atual,0) `Quantidade`, p.estoque_minimo `Mínimo`
              FROM produto p LEFT JOIN estoque e ON e.id_produto=p.id_produto WHERE p.ativo=1 AND COALESCE(e.quantidade_atual,0)<=p.estoque_minimo ORDER BY p.nome"],
          'vencimento' => [0, "SELECT p.nome `Produto`, l.numero_lote `Lote`, l.data_validade `Validade`, l.quantidade_atual `Quantidade`,
              IF(l.data_validade<CURDATE(),'Vencido','A vencer') `Situação`
              FROM lote l JOIN produto p ON p.id_produto=l.id_produto WHERE p.ativo=1 AND l.quantidade_atual>0 AND l.data_validade<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY l.data_validade"],
          'compras' => [1, "SELECT c.id_compra `Compra`, c.data_compra `Data`, f.nome `Fornecedor`, fu.nome `Funcionário`, c.valor_total `Total (R$)`, c.status `Status`
              FROM compra c JOIN fornecedor f ON f.id_fornecedor=c.id_fornecedor JOIN funcionario fu ON fu.id_funcionario=c.id_funcionario
              WHERE DATE(c.data_compra) BETWEEN ? AND ? ORDER BY c.data_compra DESC"],
          'movimentacoes' => [1, "SELECT m.data_movimentacao `Data`, p.nome `Produto`, l.numero_lote `Lote`, m.tipo_movimentacao `Tipo`, m.quantidade `Quantidade`, m.motivo `Motivo`, f.nome `Funcionário`
              FROM movimentacao_estoque m JOIN produto p ON p.id_produto=m.id_produto LEFT JOIN lote l ON l.id_lote=m.id_lote JOIN funcionario f ON f.id_funcionario=m.id_funcionario
              WHERE DATE(m.data_movimentacao) BETWEEN ? AND ? ORDER BY m.data_movimentacao DESC LIMIT 1000"],
          'caixa' => [1, "SELECT c.id_caixa `Caixa`, f.nome `Funcionário`, c.data_abertura `Abertura`, c.data_fechamento `Fechamento`, c.valor_abertura `Valor abertura (R$)`, c.valor_fechamento `Valor fechamento (R$)`, c.status `Status`
              FROM caixa c JOIN funcionario f ON f.id_funcionario=c.id_funcionario WHERE DATE(c.data_abertura) BETWEEN ? AND ? ORDER BY c.id_caixa DESC"],
        ];
        if (!isset($R[$mt[1]])) throw new Falha('Relatório inexistente.', 404);
        out(all($R[$mt[1]][1], $R[$mt[1]][0] ? [$de, $ate] : []));
    }

    // ---------- AJUSTE MANUAL DE ESTOQUE ----------
    if ($rota === '/estoque/ajuste' && $m === 'POST') {
        if (!preg_match('/admin|gerente/i', $u['cargo'])) throw new Falha('Apenas gerente ou administrador pode ajustar o estoque.', 403);
        $tipo = $b['tipo'] ?? ''; $qtd = (int)($b['quantidade'] ?? 0);
        $mot = trim($b['motivo'] ?? ''); $obs = trim($b['observacao'] ?? '');
        if (!in_array($tipo, ['Entrada', 'Saida'], true)) throw new Falha('Tipo de ajuste inválido.');
        if ($qtd <= 0) throw new Falha('A quantidade deve ser maior que zero.');
        if ($mot === '') throw new Falha('Informe o motivo do ajuste.');
        $res = tx(function () use ($b, $tipo, $qtd, $mot, $obs, $u) {
            $l = one('SELECT l.*, p.nome FROM lote l JOIN produto p ON p.id_produto=l.id_produto WHERE l.id_lote=? FOR UPDATE', [$b['id_lote'] ?? 0]);
            if (!$l) throw new Falha('Lote não encontrado.');
            if ($tipo === 'Saida') {
                if ($qtd > (int)$l['quantidade_atual']) throw new Falha('Quantidade maior que o disponível no lote (' . $l['quantidade_atual'] . ').');
                $s = -$qtd;
            } else {
                if ($l['data_validade'] < date('Y-m-d')) throw new Falha('Não é possível dar entrada em lote vencido.');
                $s = $qtd;
            }
            q('UPDATE lote SET quantidade_atual=quantidade_atual+? WHERE id_lote=?', [$s, $l['id_lote']]);
            $up = q('UPDATE estoque SET quantidade_atual=quantidade_atual+? WHERE id_produto=? AND quantidade_atual+?>=0', [$s, $l['id_produto'], $s]);
            if (!$up->rowCount()) {
                if ($tipo === 'Saida') throw new Falha('Estoque negativo não permitido.');
                q('INSERT INTO estoque (id_produto,quantidade_atual,quantidade_minima) SELECT id_produto,?,estoque_minimo FROM produto WHERE id_produto=?', [$qtd, $l['id_produto']]);
            }
            $texto = mb_substr('Ajuste manual: ' . $mot . ($obs !== '' ? ' - ' . $obs : ''), 0, 200);
            q('INSERT INTO movimentacao_estoque (id_produto,id_lote,id_funcionario,tipo_movimentacao,quantidade,motivo) VALUES (?,?,?,?,?,?)',
              [$l['id_produto'], $l['id_lote'], $u['id'], $tipo, $qtd, $texto]);
            return ['produto' => $l['nome'], 'novaQuantidade' => (int)$l['quantidade_atual'] + $s];
        });
        out($res);
    }

    // ---------- CANCELAMENTO DE VENDA ----------
    if (preg_match('#^/vendas/(\d+)/cancelar$#', $rota, $mt) && $m === 'POST') {
        if (!preg_match('/admin|gerente/i', $u['cargo'])) throw new Falha('Apenas gerente ou administrador pode cancelar vendas.', 403);
        $mot = trim($b['motivo'] ?? '');
        if ($mot === '') throw new Falha('Informe o motivo do cancelamento.');
        $res = tx(function () use ($mt, $mot, $u) {
            $v = one('SELECT v.*, c.status caixa_status FROM venda v JOIN caixa c ON c.id_caixa=v.id_caixa WHERE v.id_venda=? FOR UPDATE', [$mt[1]]);
            if (!$v) throw new Falha('Venda não encontrada.', 404);
            if ($v['status'] !== 'Concluida') throw new Falha('Esta venda já foi cancelada ou não está concluída.');
            if ($v['caixa_status'] !== 'Aberto') throw new Falha('Não é possível cancelar: o caixa desta venda já foi fechado.');
            $itens = all('SELECT id_produto,id_lote,quantidade FROM item_venda WHERE id_venda=?', [$v['id_venda']]);
            foreach ($itens as $it) {
                if ($it['id_lote']) q('UPDATE lote SET quantidade_atual=quantidade_atual+? WHERE id_lote=?', [$it['quantidade'], $it['id_lote']]);
                $up = q('UPDATE estoque SET quantidade_atual=quantidade_atual+? WHERE id_produto=?', [$it['quantidade'], $it['id_produto']]);
                if (!$up->rowCount()) q('INSERT INTO estoque (id_produto,quantidade_atual,quantidade_minima) SELECT id_produto,?,estoque_minimo FROM produto WHERE id_produto=?', [$it['quantidade'], $it['id_produto']]);
                q("INSERT INTO movimentacao_estoque (id_produto,id_lote,id_funcionario,tipo_movimentacao,quantidade,motivo) VALUES (?,?,?,'Entrada',?,?)",
                  [$it['id_produto'], $it['id_lote'], $u['id'], $it['quantidade'], mb_substr("Cancelamento da venda #{$v['id_venda']}: $mot", 0, 200)]);
            }
            q("UPDATE venda SET status='Cancelada' WHERE id_venda=?", [$v['id_venda']]);
            q("UPDATE pagamento SET status='Estornado' WHERE id_venda=?", [$v['id_venda']]);
            return ['id_venda' => (int)$v['id_venda'], 'itens' => count($itens)];
        });
        out($res);
    }

    throw new Falha('Rota não encontrada.', 404);

} catch (Falha $e) { out(['erro' => $e->getMessage()], $e->c); }
catch (PDOException $e) {
    $c = $e->errorInfo[1] ?? 0;
    out(['erro' => $c == 1062 ? 'Registro duplicado (CNPJ ou código de barras já cadastrado).' : ($c == 1451 ? 'Registro possui vínculos e não pode ser excluído.' : 'Erro no banco de dados.')], 500);
}
catch (Throwable $e) { out(['erro' => 'Erro interno do servidor.'], 500); }
