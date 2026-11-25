import { api } from '../api.js';
import { badge, confirmDialog, field, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { ESTIMATE_SECTIONS, ESTIMATE_STATUS, ESTIMATE_TONE, num } from '../ops-labels.js';
import { can } from '../perm.js';

function showError(box, err) {
  box.textContent = errText(err);
  box.classList.remove('hidden');
}

/** Окно правки сметы: название, наценка, скидка и список позиций. */
function editModal(estimate, projectId, onDone) {
  const isNew = !estimate;
  const items = estimate ? estimate.items.map((i) => ({ ...i })) : [];
  const title = h('input', { name: 'title', maxlength: '200', value: estimate ? estimate.title : '', placeholder: 'Например: Дом 120 м2, коробка' });
  const markup = h('input', { name: 'markup_pct', type: 'number', min: '0', step: 'any', value: String(estimate ? estimate.markup_pct : 0) });
  const discount = h('input', { name: 'discount_pct', type: 'number', min: '0', max: '100', step: 'any', value: String(estimate ? estimate.discount_pct : 0) });
  const extra = h('input', { name: 'extra', type: 'number', min: '0', step: '1000', value: String(estimate ? estimate.extra : 0) });
  const rows = h('div', { class: 'list' });
  const total = h('strong', null);
  const error = h('div', { class: 'error hidden', role: 'alert' });

  const recount = () => {
    const sub = items.reduce((s, i) => s + (Number(i.quantity) || 0) * (Number(i.price) || 0), 0);
    const result = sub * (1 + (Number(markup.value) || 0) / 100) * (1 - (Number(discount.value) || 0) / 100) + (Number(extra.value) || 0);
    total.textContent = `Итого: ${fmtMoney(Math.round(result * 100) / 100)}`;
  };
  const draw = () => {
    rows.replaceChildren(...items.map((it, idx) => {
      const bind = (key, input) => { input.addEventListener('input', () => { it[key] = input.value; recount(); }); return input; };
      return h('div', { class: 'est-row' },
        h('select', { 'aria-label': 'Раздел', onchange: (e) => { it.section = e.target.value; recount(); } }, Object.entries(ESTIMATE_SECTIONS).map(([k, t]) => h('option', { value: k, selected: k === it.section }, t))),
        bind('title', h('input', { value: it.title, maxlength: '200', placeholder: 'Что', 'aria-label': 'Позиция' })),
        bind('quantity', h('input', { type: 'number', min: '0', step: 'any', value: String(it.quantity), 'aria-label': 'Количество' })),
        bind('unit', h('input', { value: it.unit || '', maxlength: '30', placeholder: 'ед.', 'aria-label': 'Единица' })),
        bind('price', h('input', { type: 'number', min: '0', step: 'any', value: String(it.price), 'aria-label': 'Цена' })),
        h('button', { class: 'btn small', type: 'button', 'aria-label': 'Убрать позицию', onclick: () => { items.splice(idx, 1); draw(); recount(); } }, '×'));
    }));
  };
  [markup, discount, extra].forEach((i) => i.addEventListener('input', recount));

  const submit = async (e) => {
    e.preventDefault();
    const name = title.value.trim();
    if (!name) { error.textContent = 'Напишите название сметы.'; error.classList.remove('hidden'); return; }
    const body = {
      title: name, markup_pct: Number(markup.value) || 0, discount_pct: Number(discount.value) || 0, extra: Number(extra.value) || 0,
      items: items.filter((i) => String(i.title).trim()).map((i) => ({ section: i.section, title: String(i.title).trim(), quantity: Number(i.quantity) || 0, unit: i.unit || '', price: Number(i.price) || 0, material_id: i.material_id || null })),
    };
    try {
      if (isNew) await api.post(`/projects/${projectId}/estimates`, body); else await api.put(`/estimates/${estimate.id}`, body);
      dialog.close(); toast('Смета сохранена'); onDone();
    } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error, field('Название', title),
    h('div', { class: 'row' },
      h('div', { style: 'flex:1;min-width:110px' }, field('Наценка, %', markup)), h('div', { style: 'flex:1;min-width:110px' }, field('Скидка, %', discount)),
      h('div', { style: 'flex:1;min-width:110px' }, field('Доп. расходы, ₽', extra))),
    h('p', { class: 'muted' }, 'Позиции: раздел, название, количество, единица, цена за единицу.'), rows,
    h('button', { class: 'btn small', id: 'add-item', type: 'button', onclick: () => { items.push({ section: 'materials', title: '', quantity: 1, unit: '', price: 0 }); draw(); recount(); } }, '+ Позиция'),
    h('div', { class: 'actions' }, total, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')));
  const dialog = modal(isNew ? 'Новая смета' : `Смета, версия ${estimate.version}`, form);
  draw();
  recount();
}

function viewModal(estimate) {
  const bySection = Object.entries(ESTIMATE_SECTIONS).map(([key, text]) => [text, estimate.items.filter((i) => i.section === key)]).filter(([, list]) => list.length);
  modal(`${estimate.title}, версия ${estimate.version}`, h('div', null,
    bySection.map(([text, list]) => h('section', null, h('h3', null, text), h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
      h('tbody', null, list.map((i) => h('tr', null, h('td', null, i.title), h('td', null, `${num(i.quantity)} ${i.unit}`), h('td', null, fmtMoney(i.price)), h('td', null, fmtMoney(i.sum))))))))),
    h('p', { class: 'muted' }, `Позиции ${fmtMoney(estimate.subtotal)}, наценка ${fmtMoney(estimate.markup)}, скидка ${fmtMoney(estimate.discount)}, доп. расходы ${fmtMoney(estimate.extra)}`),
    h('p', null, h('strong', null, `Итого: ${fmtMoney(estimate.total)}`))));
}

async function compareModal(a, b) {
  const d = await api.get('/estimates/compare', { a, b });
  const line = (r) => h('li', null, `${ESTIMATE_SECTIONS[r.section]}: ${r.title}, ${num(r.quantity)} ${r.unit} × ${fmtMoney(r.price)}`);
  modal(`Версия ${d.a.version} и версия ${d.b.version}`, h('div', null,
    h('p', null, h('strong', null, `Разница: ${d.diff_total > 0 ? '+' : ''}${fmtMoney(d.diff_total)}${d.diff_percent !== null ? ` (${d.diff_percent}%)` : ''}`)),
    d.added.length ? [h('h3', null, 'Добавлено'), h('ul', null, d.added.map(line))] : null,
    d.removed.length ? [h('h3', null, 'Убрано'), h('ul', null, d.removed.map(line))] : null,
    d.changed.length ? [h('h3', null, 'Изменено'), h('ul', null, d.changed.map((c) => h('li', null, `${c.title}: ${fmtMoney(c.before.sum)} → ${fmtMoney(c.after.sum)}`)))] : null,
    !d.added.length && !d.removed.length && !d.changed.length ? h('p', { class: 'muted' }, 'Версии совпадают.') : null));
}

export async function estimatesTab(box, project, ctx) {
  let pick = [];
  async function render() {
    const list = await api.get(`/projects/${project.id}/estimates`);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const write = can(ctx, 'estimates.write');
    const act = (fn, done) => async () => { try { await fn(); if (done) toast(done); reload(); } catch (e) { toast(errText(e), 'bad'); } };
    const open = async (id, fn) => { try { fn(await api.get(`/estimates/${id}`)); } catch (e) { toast(errText(e), 'bad'); } };
    const row = (e) => h('div', { class: 'item col-item' },
      h('label', { class: 'check-row' }, h('input', { type: 'checkbox', 'aria-label': `Выбрать версию ${e.version} для сравнения`, checked: pick.includes(e.id), onchange: (ev) => { pick = ev.target.checked ? [...pick, e.id].slice(-2) : pick.filter((x) => x !== e.id); render(); } })),
      h('div', { style: 'flex:1;min-width:0' }, h('strong', null, e.title), ` версия ${e.version} `, badge(ESTIMATE_STATUS[e.status], ESTIMATE_TONE[e.status]),
        h('p', { class: 'muted' }, `${fmtMoney(e.total)} · позиций ${e.items_count} · ${fmtDate(e.created_at)}${e.approved_at ? ` · утверждена ${fmtDate(e.approved_at)}` : ''}`)),
      h('div', { class: 'row' },
        h('button', { class: 'btn small', type: 'button', onclick: () => open(e.id, viewModal) }, 'Открыть'),
        write && e.status === 'draft' ? h('button', { class: 'btn small', type: 'button', onclick: () => open(e.id, (full) => editModal(full, project.id, reload)) }, 'Изменить') : null,
        write && e.status !== 'draft' ? h('button', { class: 'btn small', type: 'button', onclick: act(() => api.post(`/estimates/${e.id}/new-version`), 'Создан черновик новой версии') }, 'Новая версия') : null,
        can(ctx, 'estimates.approve') && e.status === 'draft' ? h('button', { class: 'btn small primary', type: 'button', onclick: () => confirmDialog('Утвердить смету?', 'Предыдущая утверждённая версия уйдёт в архив. Утверждённую смету править нельзя, только создать новую версию.', 'Утвердить', act(() => api.post(`/estimates/${e.id}/approve`), 'Смета утверждена')) }, 'Утвердить') : null,
        can(ctx, 'budget.write') && e.status === 'approved' ? h('button', { class: 'btn small', type: 'button', onclick: () => confirmDialog('Перенести в бюджет?', 'Плановые расходы по статьям объекта заменятся данными из сметы.', 'Перенести', act(() => api.post(`/estimates/${e.id}/apply-budget`, { set_contract_value: false }), 'Бюджет обновлён')) }, 'В бюджет') : null,
        write && e.status === 'draft' ? h('button', { class: 'btn small bad', type: 'button', onclick: () => confirmDialog('Удалить черновик?', e.title, 'Удалить', act(() => api.del(`/estimates/${e.id}`))) }, 'Удалить') : null));
    box.replaceChildren(
      h('div', { class: 'row', style: 'justify-content:space-between;align-items:center;margin-bottom:8px' },
        h('p', { class: 'muted', style: 'margin:0' }, 'Смета объекта: позиции по разделам, наценка, скидка и версии.'),
        h('div', { class: 'row' },
          pick.length === 2 ? h('button', { class: 'btn small', id: 'compare', type: 'button', onclick: () => compareModal(...[...pick].sort((x, y) => x - y)).catch((e) => toast(errText(e), 'bad')) }, 'Сравнить выбранные') : null,
          write ? h('button', { class: 'btn primary', id: 'add-estimate', type: 'button', onclick: () => editModal(null, project.id, reload) }, '+ Смета') : null)),
      list.length ? h('div', { class: 'list' }, list.map(row)) : h('div', { class: 'empty' }, h('p', null, 'Смет пока нет. Составьте первую: утверждённая смета переносится в бюджет объекта.')));
  }
  await render();
}
