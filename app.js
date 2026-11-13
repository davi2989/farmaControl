const $ = s => document.querySelector(s);
const brl = n => Number(n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let token = localStorage.getItem('fc_token'), user = JSON.parse(localStorage.getItem('fc_user') || 'null');

function toast(msg, err) {
  const t = $('#toast'); t.textContent = msg; t.className = 'toast' + (err ? ' err' : '');
  clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.add('hidden'), 3500);
}
async function api(url, method = 'GET', body) {
  const [pth, qs] = url.split('?');
  const r = await fetch('api.php?r=' + pth + (qs ? '&' + qs : ''), {
    method, headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined
  });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401 && url !== '/login') { logout(true); throw new Error(d.erro); }
  if (!r.ok) throw new Error(d.erro || 'Erro');
  return d;
}
const safe = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };
const modal = (html, wide) => { $('#modalBox').className = 'modal-box' + (wide ? ' wide' : ''); $('#modalBox').innerHTML = html; $('#modal').classList.remove('hidden'); };
const closeModal = () => $('#modal').classList.add('hidden');

// ---------- login ----------
$('#loginForm').onsubmit = safe(async e => {
  e.preventDefault();
  const d = await api('/login', 'POST', { login: $('#lg').value, senha: $('#ls').value });
  token = 'sessao'; user = d.user;
  localStorage.setItem('fc_token', token); localStorage.setItem('fc_user', JSON.stringify(user));
  start();
});
async function logout(silent) {
  if (!silent) await api('/logout', 'POST').catch(() => {});
  localStorage.clear(); token = user = null;
  $('#app').classList.add('hidden'); $('#login').classList.remove('hidden');
}
$('#sair').onclick = () => logout();
function start() {
  if (!token) return;
  $('#login').classList.add('hidden'); $('#app').classList.remove('hidden');
  $('#uname').textContent = user.nome; go('inicio');
}
const pages = {};
function go(p) {
  document.querySelectorAll('#nav a').forEach(a => a.classList.toggle('on', a.dataset.p === p));
  safe(pages[p])();
}
$('#nav').onclick = e => e.target.dataset.p && go(e.target.dataset.p);
const head = (ico, t, sub, extra = '') => `<div class="head"><div class="ico">${ico}</div><div><h1>${t}</h1><small>${sub}</small></div><div class="sp"></div>${extra}</div>`;

// ---------- início ----------
pages.inicio = async () => {
  const d = await api('/dashboard');
  $('#view').innerHTML = head('🏠', 'Painel Inicial', 'Resumo do dia na farmácia') + `<div class="kpis">
    <div class="kpi ok"><span>Valor vendido hoje</span><b>${brl(d.totalHoje)}</b></div>
    <div class="kpi"><span>Vendas hoje</span><b>${d.vendasHoje}</b></div>
    <div class="kpi"><span>Produtos cadastrados</span><b>${d.produtos}</b></div>
    <div class="kpi warn"><span>Estoque baixo</span><b>${d.estoqueBaixo}</b></div>
    <div class="kpi warn"><span>Vencem em 30 dias</span><b>${d.aVencer}</b></div>
    <div class="kpi bad"><span>Lotes vencidos</span><b>${d.vencidos}</b></div></div>`;
};

// ---------- produtos ----------
const CATS = ['Analgésicos', 'Antibióticos', 'Anti-inflamatórios', 'Vitaminas', 'Higiene', 'Dermocosméticos', 'Outros'];
pages.produtos = async () => {
  $('#view').innerHTML = head('💊', 'Cadastro de Produtos', 'Cadastre e gerencie os produtos da farmácia.',
    '<button class="btn primary" id="novo">+ Novo Produto</button>') +
    `<div class="card"><div class="search"><input id="q" placeholder="Pesquisar por nome ou código de barras..."></div><div id="tb"></div></div>`;
  $('#novo').onclick = () => formProduto();
  $('#q').oninput = safe(listarProdutos);
  await listarProdutos();
};
let cacheProd = [];
async function listarProdutos() {
  cacheProd = await api('/produtos?q=' + encodeURIComponent($('#q').value));
  $('#tb').innerHTML = `<table><tr><th>Código</th><th>Produto</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Ações</th></tr>` +
    (cacheProd.map(p => `<tr><td>${esc(p.codigo_barras)}</td><td>${esc(p.nome)}${p.controlado ? ' 🔒' : ''}</td><td>${esc(p.categoria)}</td>
    <td>${brl(p.preco_venda)}</td><td>${p.estoque}</td><td>
    <button class="ib e" onclick="formProduto(${p.id_produto})">✎</button> <button class="ib d" onclick="delProduto(${p.id_produto})">🗑</button></td></tr>`).join('')
    || '<tr><td colspan="6">Nenhum produto encontrado.</td></tr>') + '</table>';
}
window.formProduto = id => {
  const p = cacheProd.find(x => x.id_produto === id) || {};
  const novo = !id;
  modal(`<h2>${novo ? 'Novo produto' : 'Editar produto'}</h2><div class="grid" style="margin-top:12px">
    <label class="w2">Nome do Produto *<input id="f_nome" value="${esc(p.nome)}"></label>
    <label>Código de Barras<input id="f_cb" value="${esc(p.codigo_barras)}"></label>
    <label>Categoria<select id="f_cat">${CATS.map(c => `<option ${c === p.categoria ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
    <label>Preço de Venda (R$) *<input id="f_pv" type="number" step="0.01" min="0" value="${p.preco_venda ?? ''}"></label>
    <label>Preço de Compra (R$)<input id="f_pc" type="number" step="0.01" min="0" value="${p.preco_custo ?? ''}"></label>
    <label>Estoque Mínimo<input id="f_min" type="number" min="0" value="${p.estoque_minimo ?? 0}"></label>
    ${novo ? `<label>Estoque Inicial<input id="f_ini" type="number" min="0" value="0"></label>
    <label>Nº do Lote<input id="f_lote"></label><label>Validade do Lote<input id="f_val" type="date"></label>` : ''}
    <label class="w2" style="grid-template-columns:auto 1fr;align-items:center;gap:8px"><input type="checkbox" id="f_ctl" style="width:auto" ${p.controlado ? 'checked' : ''}>Medicamento Controlado</label>
    <label class="w2">Tipo de controle<input id="f_tipo" value="${esc(p.tipo_controle)}" placeholder="Ex.: Portaria 344 - B1"></label>
  </div><div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="salvar">Salvar</button></div>`);
  $('#salvar').onclick = safe(async () => {
    const b = { nome: $('#f_nome').value, codigo_barras: $('#f_cb').value.trim(), categoria: $('#f_cat').value,
      preco_venda: $('#f_pv').value, preco_custo: $('#f_pc').value, estoque_minimo: $('#f_min').value,
      controlado: $('#f_ctl').checked, tipo_controle: $('#f_tipo').value };
    if (novo) { b.estoque_inicial = $('#f_ini').value; b.numero_lote = $('#f_lote').value; b.data_validade = $('#f_val').value; }
    await api(novo ? '/produtos' : '/produtos/' + id, novo ? 'POST' : 'PUT', b);
    closeModal(); toast('Produto salvo com sucesso!'); listarProdutos();
  });
};
window.delProduto = safe(async id => {
  if (!confirm('Desativar este produto? O histórico de vendas será preservado.')) return;
  await api('/produtos/' + id, 'DELETE'); toast('Produto desativado.'); listarProdutos();
});

// ---------- estoque ----------
let lotesCache = [];
pages.estoque = async () => {
  const d = await api('/estoque'); lotesCache = d.lotes;
  $('#view').innerHTML = head('📦', 'Controle de Estoque', 'Visualize e ajuste o estoque por lote.', '<button class="btn" onclick="go(\'estoque\')">⟳ Atualizar</button>') +
    `<div class="kpis"><div class="kpi"><span>Total de Produtos</span><b>${d.total}</b></div><div class="kpi ok"><span>Estoque OK</span><b>${d.ok}</b></div>
    <div class="kpi warn"><span>Estoque Baixo</span><b>${d.baixo}</b></div><div class="kpi bad"><span>Lotes Vencidos</span><b>${d.vencidos}</b></div></div>
    <div class="card"><table><tr><th>Produto</th><th>Lote</th><th>Validade</th><th>Quantidade</th><th>Mínimo</th><th>Status</th><th>Ajuste</th></tr>` +
    (d.lotes.map(l => `<tr><td>${esc(l.produto)}</td><td>${esc(l.lote)}</td><td>${fdata(l.validade)}</td><td>${l.quantidade}</td><td>${l.minimo}</td>
    <td><span class="tag ${l.status}">${l.status}</span></td><td><button class="ib e" title="Ajustar estoque" onclick="ajustarLote(${l.id_lote})">⚙</button></td></tr>`).join('')
    || '<tr><td colspan="7">Nenhum lote cadastrado.</td></tr>') + '</table></div>';
};
window.ajustarLote = id => {
  const l = lotesCache.find(x => x.id_lote == id), venc = l.status === 'Vencido';
  const MOT = ['Vencimento', 'Perda / avaria', 'Correção de inventário', 'Devolução ao fornecedor', 'Doação / amostra', 'Outro'];
  modal(`<h2>Ajuste de estoque</h2><p style="margin:8px 0 12px"><b>${esc(l.produto)}</b><br><small>Lote ${esc(l.lote)} · validade ${fdata(l.validade)} · disponível: ${l.quantidade}</small></p>
    <div class="grid"><label class="w2">Tipo<select id="aj_t"><option value="Saida">Saída (baixa)</option><option value="Entrada">Entrada</option></select></label>
    <label class="w2">Quantidade<input id="aj_q" type="number" min="1" value="${venc ? l.quantidade : ''}"></label>
    <label class="w2">Motivo<select id="aj_m">${MOT.map(m => `<option ${venc && m === 'Vencimento' ? 'selected' : ''}>${m}</option>`).join('')}</select></label>
    <label class="w2">Observação<input id="aj_o"></label></div>
    <div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="aj_ok">Confirmar ajuste</button></div>`);
  $('#aj_ok').onclick = safe(async () => {
    const t = $('#aj_t').value, n = parseInt($('#aj_q').value);
    if (!(n > 0)) throw new Error('Informe uma quantidade válida.');
    if (!confirm(`Confirmar ${t === 'Saida' ? 'saída' : 'entrada'} de ${n} un. de "${l.produto}"?`)) return;
    await api('/estoque/ajuste', 'POST', { id_lote: l.id_lote, tipo: t, quantidade: n, motivo: $('#aj_m').value, observacao: $('#aj_o').value });
    closeModal(); toast('Ajuste registrado com sucesso!'); go('estoque');
  });
};

// ---------- vendas (PDV) ----------
let cart = [], cliente = null;
pages.vendas = async () => {
  const cx = await api('/caixa/atual');
  if (!cx) {
    $('#view').innerHTML = head('🛒', 'Ponto de Venda', 'O caixa está fechado.') +
      `<div class="card" style="max-width:380px"><label>Valor inicial do caixa (R$)<input id="vi" type="number" step="0.01" min="0" value="0"></label>
      <div class="actions"><button class="btn primary" id="abrir">Abrir caixa</button></div></div>`;
    $('#abrir').onclick = safe(async () => { await api('/caixa/abrir', 'POST', { valor: $('#vi').value }); toast('Caixa aberto!'); go('vendas'); });
    return;
  }
  cart = []; cliente = null;
  $('#view').innerHTML = head('🛒', 'Ponto de Venda (Carrinho)', 'Adicione produtos e finalize a venda.') + `<div class="pdv">
    <div class="card"><div class="search"><input id="pq" placeholder="Pesquisar por nome ou código de barras..."></div><div id="plist" class="plist"></div></div>
    <div class="card"><b>Carrinho de Compras</b><div id="cart"></div>
      <label style="margin-top:12px">Cliente (opcional)<input id="cq" placeholder="Nome ou CPF do cliente..."></label><div id="cs"></div>
      <label style="margin-top:8px">Desconto (R$) <small>(gerente/admin)</small><input id="desc" type="number" step="0.01" min="0" value="0"></label>
      <div id="tot"></div><button class="btn primary big" id="fin">Finalizar Venda →</button></div></div>`;
  $('#pq').oninput = safe(buscarPDV); $('#desc').oninput = renderCart;
  $('#cq').oninput = safe(async () => {
    cliente = null;
    const q = $('#cq').value; if (q.length < 2) return $('#cs').innerHTML = '';
    const r = await api('/clientes?q=' + encodeURIComponent(q));
    $('#cs').innerHTML = '<div class="sugg">' + r.map(c => `<div data-id="${c.id_cliente}" data-n="${esc(c.nome)}">${esc(c.nome)} ${esc(c.cpf)}</div>`).join('') + '</div>';
  });
  $('#cs').onclick = e => { const d = e.target.closest('[data-id]'); if (d) { cliente = +d.dataset.id; $('#cq').value = d.dataset.n; $('#cs').innerHTML = ''; } };
  $('#fin').onclick = pagamento;
  await buscarPDV(); renderCart();
};
async function buscarPDV() {
  const r = await api('/produtos?q=' + encodeURIComponent($('#pq').value)); cacheProd = r;
  $('#plist').innerHTML = r.map(p => `<div class="row"><span class="nm">${esc(p.nome)}${p.controlado ? ' 🔒' : ''}<br><small>Estoque: ${p.estoque}</small></span>
    <span class="pr">${brl(p.preco_venda)}</span><button class="add" onclick="addCart(${p.id_produto})">+</button></div>`).join('') || 'Nenhum produto.';
}
window.addCart = id => {
  const p = cacheProd.find(x => x.id_produto === id), it = cart.find(x => x.id_produto === id);
  if ((it ? it.q : 0) + 1 > p.estoque) return toast('Estoque insuficiente.', true);
  it ? it.q++ : cart.push({ id_produto: id, nome: p.nome, preco: Number(p.preco_venda), q: 1, ctl: +p.controlado > 0, estoque: p.estoque });
  renderCart();
};
window.qtd = (i, d) => { const it = cart[i]; it.q += d; if (it.q > it.estoque) { it.q = it.estoque; toast('Estoque insuficiente.', true); } if (it.q <= 0) cart.splice(i, 1); renderCart(); };
const subtotal = () => cart.reduce((s, i) => s + i.preco * i.q, 0);
const desconto = () => Math.max(0, Number($('#desc')?.value) || 0);
function renderCart() {
  $('#cart').innerHTML = '<table>' + (cart.map((i, n) => `<tr><td>${esc(i.nome)}<br><small>${brl(i.preco)}</small></td>
    <td><span class="qty"><button onclick="qtd(${n},-1)">−</button>${i.q}<button onclick="qtd(${n},1)">+</button></span></td><td>${brl(i.preco * i.q)}</td></tr>`).join('')
    || '<tr><td>Carrinho vazio.</td></tr>') + '</table>';
  $('#tot').innerHTML = `<div class="sum"><span>Subtotal</span><b>${brl(subtotal())}</b></div><div class="sum"><span>Desconto</span><b>${brl(desconto())}</b></div>
    <div class="total"><span>Total</span><span>${brl(Math.max(0, subtotal() - desconto()))}</span></div>`;
}

// ---------- pagamento ----------
async function pagamento() {
  if (!cart.length) return toast('Adicione produtos ao carrinho.', true);
  const formas = await api('/formas-pagamento'), total = Math.max(0, subtotal() - desconto());
  const ico = { Dinheiro: '💵', Pix: '◆', 'Cartao de debito': '💳', 'Cartao de credito': '💳', Convenio: '🏥' };
  const precisaRec = cart.some(i => i.ctl); let sel = formas[0];
  modal(`<h2>Pagamento</h2><small>Escolha a forma de pagamento e finalize a venda.</small>
    <div class="methods">${formas.map(f => `<div class="method" data-id="${f.id_forma_pagamento}"><div>${ico[f.descricao] || '💰'}</div>${esc(f.descricao)}</div>`).join('')}</div>
    <div class="sum"><span>Total</span><b>${brl(total)}</b></div>
    <label>Valor Recebido (R$)<input id="rec" type="number" step="0.01" min="0" value="${total.toFixed(2)}"></label><div id="tr"></div>
    ${precisaRec ? `<h3>Receita (medicamento controlado)</h3><div class="grid"><label class="w2">Nome do médico<input id="r_med"></label><label class="w2">CRM<input id="r_crm"></label>
    <label class="w2">Data de emissão<input id="r_em" type="date"></label><label class="w2">Validade<input id="r_val" type="date"></label></div>` : ''}
    <div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="ok">Confirmar Pagamento ✓</button></div>`);
  const marca = () => document.querySelectorAll('.method').forEach(m => m.classList.toggle('on', +m.dataset.id === sel.id_forma_pagamento));
  const troco = () => { const t = Number($('#rec').value) - total; $('#tr').innerHTML = sel.descricao === 'Dinheiro' && t > 0 ? `<div class="troco">Troco: ${brl(t)}</div>` : ''; };
  document.querySelectorAll('.method').forEach(m => m.onclick = () => { sel = formas.find(f => f.id_forma_pagamento === +m.dataset.id); marca(); troco(); });
  $('#rec').oninput = troco; marca();
  $('#ok').onclick = safe(async () => {
    const r = await api('/vendas', 'POST', {
      itens: cart.map(i => ({ id_produto: i.id_produto, quantidade: i.q })), id_cliente: cliente, desconto: desconto(),
      pagamentos: [{ id_forma_pagamento: sel.id_forma_pagamento, valor: Number($('#rec').value), dinheiro: sel.descricao === 'Dinheiro' }],
      receita: precisaRec ? { nome_medico: $('#r_med').value, crm: $('#r_crm').value, data_emissao: $('#r_em').value, data_validade: $('#r_val').value } : null
    });
    closeModal(); toast(`Venda #${r.id_venda} finalizada: ${brl(r.total)}`); go('vendas');
  });
}
// ---------- fornecedores ----------
let cacheForn = [];
const fdata = s => s ? s.slice(0, 10).split('-').reverse().join('/') : '';
pages.fornecedores = async () => {
  $('#view').innerHTML = head('🚚', 'Fornecedores', 'Cadastre e gerencie seus fornecedores.', '<button class="btn primary" id="nf">+ Novo Fornecedor</button>') +
    '<div class="card"><div class="search"><input id="fq" placeholder="Pesquisar por nome ou CNPJ..."></div><div id="ft"></div></div>';
  $('#nf').onclick = () => formForn(); $('#fq').oninput = safe(listarForn); await listarForn();
};
async function listarForn() {
  cacheForn = await api('/fornecedores?q=' + encodeURIComponent($('#fq').value));
  $('#ft').innerHTML = '<table><tr><th>Nome</th><th>CNPJ</th><th>Telefone</th><th>E-mail</th><th>Ações</th></tr>' +
    (cacheForn.map(f => `<tr><td>${esc(f.nome)}</td><td>${esc(f.cnpj)}</td><td>${esc(f.telefone)}</td><td>${esc(f.email)}</td>
    <td><button class="ib e" onclick="formForn(${f.id_fornecedor})">✎</button> <button class="ib d" onclick="delForn(${f.id_fornecedor})">🗑</button></td></tr>`).join('')
    || '<tr><td colspan="5">Nenhum fornecedor cadastrado.</td></tr>') + '</table>';
}
window.formForn = id => {
  const f = cacheForn.find(x => x.id_fornecedor === id) || {};
  modal(`<h2>${id ? 'Editar fornecedor' : 'Novo fornecedor'}</h2><div class="grid" style="margin-top:12px">
    <label class="w2">Nome *<input id="g_nome" value="${esc(f.nome)}"></label><label class="w2">CNPJ<input id="g_cnpj" value="${esc(f.cnpj)}"></label>
    <label class="w2">Telefone<input id="g_tel" value="${esc(f.telefone)}"></label><label class="w2">E-mail<input id="g_mail" value="${esc(f.email)}"></label>
    <label style="grid-column:span 4">Endereço<input id="g_end" value="${esc(f.endereco)}"></label></div>
    <div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="gs">Salvar</button></div>`);
  $('#gs').onclick = safe(async () => {
    await api(id ? '/fornecedores/' + id : '/fornecedores', id ? 'PUT' : 'POST', { nome: $('#g_nome').value, cnpj: $('#g_cnpj').value.trim(),
      telefone: $('#g_tel').value, email: $('#g_mail').value.trim(), endereco: $('#g_end').value });
    closeModal(); toast('Fornecedor salvo com sucesso!'); listarForn();
  });
};
window.delForn = safe(async id => {
  if (!confirm('Excluir este fornecedor?')) return;
  await api('/fornecedores/' + id, 'DELETE'); toast('Fornecedor excluído.'); listarForn();
});

// ---------- compras ----------
let itensC = [];
pages.compras = async () => {
  const r = await api('/compras');
  $('#view').innerHTML = head('🧾', 'Compras', 'Registre a entrada de produtos e lotes.', '<button class="btn primary" onclick="novaCompra()">+ Nova Compra</button>') +
    '<div class="card"><table><tr><th>Nº</th><th>Data</th><th>Fornecedor</th><th>Responsável</th><th>Total</th><th>Status</th></tr>' +
    (r.map(c => `<tr><td>${c.id_compra}</td><td>${fdata(c.data_compra)}</td><td>${esc(c.fornecedor)}</td><td>${esc(c.funcionario)}</td><td>${brl(c.valor_total)}</td>
    <td><span class="tag OK">${esc(c.status)}</span></td></tr>`).join('') || '<tr><td colspan="6">Nenhuma compra registrada.</td></tr>') + '</table></div>';
};
window.novaCompra = safe(async () => {
  const [forn, prod] = await Promise.all([api('/fornecedores'), api('/produtos')]);
  if (!forn.length) return toast('Cadastre um fornecedor antes de registrar uma compra.', true);
  if (!prod.length) return toast('Cadastre produtos antes de registrar uma compra.', true);
  itensC = [];
  modal(`<h2>Nova compra</h2><label style="margin:10px 0">Fornecedor *<select id="c_forn">${forn.map(f => `<option value="${f.id_fornecedor}">${esc(f.nome)}</option>`).join('')}</select></label>
    <div class="grid"><label class="w2">Produto<select id="i_prod">${prod.map(p => `<option value="${p.id_produto}">${esc(p.nome)}</option>`).join('')}</select></label>
    <label>Nº do lote<input id="i_lote"></label><label>Quantidade<input id="i_qtd" type="number" min="1"></label>
    <label>Fabricação<input id="i_fab" type="date"></label><label>Validade *<input id="i_val" type="date"></label>
    <label>Preço unitário (R$)<input id="i_pr" type="number" step="0.01" min="0"></label><label><span>&nbsp;</span><button class="btn primary" id="i_add">Adicionar item</button></label></div>
    <div id="c_itens" style="margin-top:12px"></div>
    <div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="c_ok">Confirmar compra ✓</button></div>`, true);
  const nomes = Object.fromEntries(prod.map(p => [p.id_produto, p.nome]));
  const render = () => {
    const tot = itensC.reduce((s, i) => s + i.quantidade * i.preco_unitario, 0);
    $('#c_itens').innerHTML = '<table><tr><th>Produto</th><th>Lote</th><th>Validade</th><th>Qtd</th><th>Unitário</th><th>Subtotal</th><th></th></tr>' +
      (itensC.map((i, n) => `<tr><td>${esc(nomes[i.id_produto])}</td><td>${esc(i.numero_lote)}</td><td>${fdata(i.data_validade)}</td><td>${i.quantidade}</td>
      <td>${brl(i.preco_unitario)}</td><td>${brl(i.quantidade * i.preco_unitario)}</td><td><button class="ib d" onclick="rmItemC(${n})">✕</button></td></tr>`).join('')
      || '<tr><td colspan="7" class="hint">Nenhum item adicionado.</td></tr>') + `</table><div class="total"><span>Total</span><span>${brl(tot)}</span></div>`;
  };
  window.rmItemC = n => { itensC.splice(n, 1); render(); };
  $('#i_add').onclick = safe(async () => {
    const it = { id_produto: +$('#i_prod').value, numero_lote: $('#i_lote').value.trim(), quantidade: parseInt($('#i_qtd').value),
      data_fabricacao: $('#i_fab').value, data_validade: $('#i_val').value, preco_unitario: parseFloat($('#i_pr').value) };
    if (!it.numero_lote) throw new Error('Informe o número do lote.');
    if (!(it.quantidade > 0)) throw new Error('Informe uma quantidade válida.');
    if (!it.data_validade) throw new Error('Informe a validade.');
    if (isNaN(it.preco_unitario) || it.preco_unitario < 0) throw new Error('Informe o preço unitário.');
    itensC.push(it); ['i_lote', 'i_qtd', 'i_fab', 'i_val', 'i_pr'].forEach(k => $('#' + k).value = ''); render();
  });
  $('#c_ok').onclick = safe(async () => {
    const r = await api('/compras', 'POST', { id_fornecedor: +$('#c_forn').value, itens: itensC });
    closeModal(); toast(`Compra #${r.id_compra} registrada: ${brl(r.total)}`); go('compras');
  });
  render();
});

// ---------- CRUD genérico ----------
function crud(c) {
  let cache = [];
  const draw = async () => {
    cache = await api(c.url + '?' + (c.qs || '') + 'q=' + encodeURIComponent(c.search ? $('#cq_').value : ''));
    $('#ct_').innerHTML = '<table><tr>' + c.cols.map(x => `<th>${x[0]}</th>`).join('') + '<th>Ações</th></tr>' +
      (cache.map(r => '<tr>' + c.cols.map(x => `<td>${x[1](r)}</td>`).join('') +
        `<td>${c.acoes ? c.acoes(r) : ''}<button class="ib e" data-a="e" data-id="${r[c.id]}">✎</button>${c.del ? ` <button class="ib d" data-a="d" data-id="${r[c.id]}">🗑</button>` : ''}</td></tr>`).join('')
        || `<tr><td colspan="${c.cols.length + 1}">Nenhum registro encontrado.</td></tr>`) + '</table>';
  };
  const form = safe(async row => {
    const r = row || {}, opts = c.load ? await c.load() : {};
    modal(`<h2>${row ? 'Editar' : 'Novo'} ${c.nome}</h2><div class="grid" style="margin-top:12px">` + c.fields.map(f => {
      const v = r[f.k] ?? '';
      const inp = f.t === 'select'
        ? `<select id="fx_${f.k}">${opts[f.k].map(o => `<option value="${o[0]}" ${String(o[0]) === String(v) ? 'selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`
        : `<input id="fx_${f.k}" type="${f.t || 'text'}" ${f.step ? `step="${f.step}" min="0"` : ''} value="${f.t === 'password' ? '' : esc(v)}" ${f.ph ? `placeholder="${f.ph}"` : ''}>`;
      return `<label class="w2">${f.l}${inp}</label>`;
    }).join('') + `</div><div class="actions"><button class="btn" onclick="closeModal()">Cancelar</button><button class="btn primary" id="fx_ok">Salvar</button></div>`);
    $('#fx_ok').onclick = safe(async () => {
      const b = {}; c.fields.forEach(f => b[f.k] = $('#fx_' + f.k).value);
      await api(row ? `${c.url}/${row[c.id]}` : c.url, row ? 'PUT' : 'POST', b);
      closeModal(); toast('Registro salvo com sucesso!'); draw();
    });
  });
  pages[c.key] = async () => {
    $('#view').innerHTML = head(c.ico, c.titulo, c.sub, '<button class="btn primary" id="cn_">+ Novo</button>') +
      `<div class="card">${c.search ? `<div class="search"><input id="cq_" placeholder="${c.search}"></div>` : ''}<div id="ct_"></div></div>`;
    $('#cn_').onclick = () => form();
    if (c.search) $('#cq_').oninput = safe(draw);
    $('#ct_').onclick = safe(async e => {
      const b = e.target.closest('[data-a]'); if (!b) return;
      const row = cache.find(x => String(x[c.id]) === b.dataset.id);
      if (b.dataset.a === 'e') form(row);
      else if (b.dataset.a === 'd') { if (confirm('Excluir este registro?')) { await api(`${c.url}/${row[c.id]}`, 'DELETE'); toast('Registro excluído.'); draw(); } }
      else await c.onAcao(b.dataset.a, row);
    });
    await draw();
  };
}
const fdt = s => s ? fdata(s) + ' ' + s.slice(11, 16) : '';

crud({ key: 'clientes', ico: '👥', titulo: 'Clientes', sub: 'Cadastre e consulte clientes.', nome: 'cliente', url: '/clientes', qs: 'full=1&', id: 'id_cliente',
  search: 'Pesquisar por nome ou CPF...', del: true,
  cols: [['Nome', r => esc(r.nome)], ['CPF', r => esc(r.cpf)], ['Telefone', r => esc(r.telefone)], ['E-mail', r => esc(r.email)]],
  fields: [{ k: 'nome', l: 'Nome *' }, { k: 'cpf', l: 'CPF', ph: '000.000.000-00' }, { k: 'telefone', l: 'Telefone' }, { k: 'email', l: 'E-mail' },
           { k: 'data_nascimento', l: 'Nascimento', t: 'date' }, { k: 'endereco', l: 'Endereço' }],
  acoes: r => `<button class="ib" style="background:#2b6cb0" title="Histórico de compras" data-a="h" data-id="${r.id_cliente}">🕘</button> `,
  onAcao: async (a, r) => {
    const h = await api(`/clientes/${r.id_cliente}/historico`);
    modal(`<h2>Histórico de ${esc(r.nome)}</h2><table style="margin-top:10px"><tr><th>Venda</th><th>Data</th><th>Itens</th><th>Total</th><th>Status</th></tr>` +
      (h.map(v => `<tr><td>${v.id_venda}</td><td>${fdt(v.data_venda)}</td><td>${esc(v.itens)}</td><td>${brl(v.valor_total)}</td><td>${esc(v.status)}</td></tr>`).join('')
      || '<tr><td colspan="5">Este cliente ainda não possui compras.</td></tr>') + '</table><div class="actions"><button class="btn" onclick="closeModal()">Fechar</button></div>', true);
  } });

crud({ key: 'funcionarios', ico: '🧑‍💼', titulo: 'Funcionários', sub: 'Gerencie a equipe e os acessos (gerente/admin).', nome: 'funcionário', url: '/funcionarios', id: 'id_funcionario',
  search: 'Pesquisar por nome ou login...',
  cols: [['Nome', r => esc(r.nome)], ['Cargo', r => esc(r.cargo)], ['Login', r => esc(r.login)], ['Admissão', r => fdata(r.data_admissao)]],
  load: async () => ({ cargo: ['Atendente', 'Farmacêutico', 'Gerente', 'Administrador'].map(x => [x, x]) }),
  fields: [{ k: 'nome', l: 'Nome *' }, { k: 'cpf', l: 'CPF' }, { k: 'cargo', l: 'Cargo', t: 'select' }, { k: 'telefone', l: 'Telefone' }, { k: 'email', l: 'E-mail' },
           { k: 'login', l: 'Login *' }, { k: 'senha', l: 'Senha', t: 'password', ph: 'Em branco = manter' }, { k: 'data_admissao', l: 'Admissão', t: 'date' },
           { k: 'salario', l: 'Salário (R$)', t: 'number', step: '0.01' }] });

crud({ key: 'impostos', ico: '🏷️', titulo: 'Impostos', sub: 'Alíquotas associadas aos produtos.', nome: 'imposto', url: '/impostos', id: 'id_imposto',
  search: 'Pesquisar por produto ou imposto...', del: true,
  cols: [['Produto', r => esc(r.produto)], ['Imposto', r => esc(r.nome_imposto)], ['Alíquota', r => Number(r.aliquota).toFixed(2) + '%']],
  load: async () => ({ id_produto: (await api('/produtos')).map(p => [p.id_produto, p.nome]) }),
  fields: [{ k: 'id_produto', l: 'Produto *', t: 'select' }, { k: 'nome_imposto', l: 'Imposto *', ph: 'ICMS, PIS, COFINS...' }, { k: 'aliquota', l: 'Alíquota (%) *', t: 'number', step: '0.01' }] });

// ---------- caixa ----------
pages.caixa = async () => {
  const r = await api('/caixa/resumo');
  if (!r) {
    $('#view').innerHTML = head('💰', 'Caixa', 'O caixa está fechado.') +
      `<div class="card" style="max-width:380px"><label>Valor inicial do caixa (R$)<input id="vi" type="number" step="0.01" min="0" value="0"></label>
      <div class="actions"><button class="btn primary" id="abrir">Abrir caixa</button></div></div>`;
    $('#abrir').onclick = safe(async () => { await api('/caixa/abrir', 'POST', { valor: $('#vi').value }); toast('Caixa aberto!'); go('caixa'); });
    return;
  }
  $('#view').innerHTML = head('💰', 'Caixa', `Aberto em ${fdt(r.caixa.data_abertura)}`) + `<div class="kpis">
    <div class="kpi"><span>Valor de abertura</span><b>${brl(r.caixa.valor_abertura)}</b></div>
    <div class="kpi"><span>Vendas (${r.qtd})</span><b>${brl(r.total)}</b></div>
    <div class="kpi"><span>Recebido em dinheiro</span><b>${brl(r.dinheiro)}</b></div>
    <div class="kpi ok"><span>Esperado na gaveta</span><b>${brl(r.esperado)}</b></div></div>
    <div class="pdv"><div class="card"><b>Recebimentos por forma</b><table style="margin-top:8px">` +
    (r.formas.map(f => `<tr><td>${esc(f.forma)}</td><td>${brl(f.total)}</td></tr>`).join('') || '<tr><td>Nenhum recebimento ainda.</td></tr>') +
    `</table></div><div class="card"><b>Fechar caixa</b><label style="margin-top:10px">Valor contado em dinheiro (R$)<input id="fc_v" type="number" step="0.01" min="0"></label>
    <div class="actions"><button class="btn primary" id="fc_ok">Fechar caixa</button></div></div></div>
    <div class="card"><b>Movimentações (vendas deste caixa)</b><table style="margin-top:8px"><tr><th>Venda</th><th>Data</th><th>Cliente</th><th>Total</th><th></th></tr>` +
    (r.vendas.map(v => `<tr><td>${v.id_venda}</td><td>${fdt(v.data_venda)}</td><td>${esc(v.cliente)}</td><td>${brl(v.valor_total)}</td><td>${podeCancelar() ? `<button class="ib d" title="Cancelar venda" onclick="cancelarVenda(${v.id_venda},${v.valor_total})">✕</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5">Nenhuma venda neste caixa.</td></tr>') + '</table></div>';
  $('#fc_ok').onclick = safe(async () => {
    const v = $('#fc_v').value; if (v === '') throw new Error('Informe o valor contado em dinheiro.');
    if (!confirm('Confirmar o fechamento do caixa?')) return;
    const d = await api('/caixa/fechar', 'POST', { valor: v });
    modal(`<h2>Caixa fechado</h2><div class="sum"><span>Esperado</span><b>${brl(d.esperado)}</b></div><div class="sum"><span>Informado</span><b>${brl(d.informado)}</b></div>
      <div class="troco" style="${d.diferenca < 0 ? 'background:#fadcdc;color:#d64545' : ''}">Diferença: ${brl(d.diferenca)}</div>
      <div class="actions"><button class="btn primary" onclick="closeModal();go('caixa')">OK</button></div>`);
  });
};

// ---------- relatórios ----------
const RELS = { vendas: ['Vendas por período', 1], mais_vendidos: ['Produtos mais vendidos', 1], estoque: ['Estoque atual', 0], estoque_baixo: ['Produtos com estoque baixo', 0],
  vencimento: ['Produtos próximos do vencimento', 0], compras: ['Compras realizadas', 1], movimentacoes: ['Movimentações de estoque', 1], caixa: ['Fechamento de caixa', 1] };
let relRows = [];
const fmtCell = (h, v) => v == null ? '' : /\(R\$\)/.test(h) ? brl(v) : /^\d{4}-\d{2}-\d{2}/.test(v) ? (v.length > 10 ? fdt(v) : fdata(v)) : v;
pages.relatorios = async () => {
  const loc = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
  const hoje = loc(new Date()), ini = hoje.slice(0, 8) + '01';
  $('#view').innerHTML = head('📊', 'Relatórios', 'Consulte, imprima ou exporte os dados.') + `<div class="card noprint"><div class="grid">
    <label class="w2">Relatório<select id="r_t">${Object.entries(RELS).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join('')}</select></label>
    <label>De<input id="r_de" type="date" value="${ini}"></label><label>Até<input id="r_ate" type="date" value="${hoje}"></label></div>
    <div class="actions"><button class="btn" id="r_csv">Exportar CSV</button><button class="btn" id="r_pr">Imprimir</button><button class="btn primary" id="r_go">Gerar</button></div></div>
    <div class="card" id="r_out"><span class="hint">Escolha o relatório e clique em Gerar.</span></div>`;
  const sync = () => { $('#r_de').disabled = $('#r_ate').disabled = !RELS[$('#r_t').value][1]; };
  $('#r_t').onchange = sync; sync();
  $('#r_go').onclick = safe(async () => {
    const t = $('#r_t').value;
    relRows = await api(`/relatorios/${t}?de=${$('#r_de').value}&ate=${$('#r_ate').value}`);
    const cols = relRows.length ? Object.keys(relRows[0]) : [];
    $('#r_out').innerHTML = `<h3 style="margin-top:0">${RELS[t][0]}</h3>` + (relRows.length
      ? '<table><tr>' + cols.map(c => `<th>${esc(c)}</th>`).join('') + '</tr>' + relRows.map(r => '<tr>' + cols.map(c => `<td>${esc(fmtCell(c, r[c]))}</td>`).join('') + '</tr>').join('') + '</table>'
      : 'Nenhum registro encontrado.');
    $('#r_out').dataset.t = t;
  });
  $('#r_pr').onclick = () => window.print();
  $('#r_csv').onclick = () => {
    if (!relRows.length) return toast('Gere um relatório com dados antes de exportar.', true);
    const cols = Object.keys(relRows[0]);
    const csv = [cols.join(';'), ...relRows.map(r => cols.map(c => `"${String(fmtCell(c, r[c])).replace(/"/g, '""')}"`).join(';'))].join('\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `relatorio-${$('#r_out').dataset.t}.csv`; a.click();
  };
};

// ---------- cancelamento de venda ----------
const podeCancelar = () => /admin|gerente/i.test((user && user.cargo) || '');
window.cancelarVenda = (id, total) => {
  modal(`<h2>Cancelar venda #${id}</h2><p style="margin:8px 0 12px">Total: <b>${brl(total)}</b>. Os itens voltarão ao estoque (nos mesmos lotes) e o pagamento será estornado. Esta ação não pode ser desfeita.</p>
    <label>Motivo do cancelamento *<input id="cv_m" maxlength="120"></label>
    <div class="actions"><button class="btn" onclick="closeModal()">Voltar</button><button class="btn primary" id="cv_ok" style="background:#d64545">Cancelar venda</button></div>`);
  $('#cv_ok').onclick = safe(async () => {
    const m = $('#cv_m').value.trim(); if (!m) throw new Error('Informe o motivo do cancelamento.');
    await api(`/vendas/${id}/cancelar`, 'POST', { motivo: m });
    closeModal(); toast(`Venda #${id} cancelada.`); go('caixa');
  });
};

start();
