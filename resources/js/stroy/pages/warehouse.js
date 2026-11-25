import { api } from '../api.js';
import { badge, field, fmtDate, fmtMoney, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';
import { MOVE_KIND, REQUEST_STATUS, REQUEST_TONE, num } from '../ops-labels.js';
import { can } from '../perm.js';

function showError(box, err) {
  box.textContent = errText(err);
  box.classList.remove('hidden');
}

function materialModal(material, onDone) {
  const name = h('input', { name: 'name', maxlength: '200', value: material ? material.name : '', placeholder: 'Например: Цемент М500' });
  const unit = h('input', { name: 'unit', maxlength: '30', value: material ? material.unit : 'шт', placeholder: 'шт, кг, м3' });
  const min = h('input', { name: 'min_stock', type: 'number', min: '0', step: 'any', value: material ? String(material.min_stock) : '0' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    const body = { name: name.value.trim(), unit: unit.value.trim() || 'шт', min_stock: Number(min.value) || 0 };
    if (!body.name) { error.textContent = 'Напишите название материала.'; error.classList.remove('hidden'); return; }
    try {
      if (material) await api.patch(`/materials/${material.id}`, body); else await api.post('/materials', body);
      dialog.close(); toast('Сохранено'); onDone();
    } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error, field('Название', name),
    h('div', { class: 'row' }, h('div', { style: 'flex:1;min-width:120px' }, field('Единица', unit)),
      h('div', { style: 'flex:1;min-width:120px' }, field('Минимальный остаток', min, 'Ниже этого числа придёт уведомление'))),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')));
  const dialog = modal(material ? 'Материал' : 'Новый материал', form);
}

function moveModal(material, projects, onDone) {
  const kind = select(MOVE_KIND, 'in', { name: 'kind' });
  const qty = h('input', { name: 'quantity', type: 'number', min: '0', step: 'any', placeholder: material.unit });
  const price = h('input', { name: 'price', type: 'number', min: '0', step: 'any', placeholder: 'Цена за единицу, ₽' });
  const projectOptions = { '': 'Выберите объект', ...Object.fromEntries(projects.map((p) => [String(p.id), p.name])) };
  const project = select(projectOptions, '', { name: 'project_id' });
  const note = h('input', { name: 'note', maxlength: '300', placeholder: 'Накладная, причина списания' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    const body = { kind: kind.value, quantity: Number(qty.value), note: note.value.trim() };
    if (!(body.quantity > 0)) { error.textContent = 'Укажите количество.'; error.classList.remove('hidden'); return; }
    if (kind.value === 'in' && price.value) body.price = Number(price.value);
    if (kind.value === 'out') body.project_id = project.value ? Number(project.value) : null;
    try { await api.post(`/materials/${material.id}/movements`, body); dialog.close(); toast('Записано'); onDone(); } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error, h('p', { class: 'muted' }, `${material.name}. Сейчас на складе: ${num(material.stock)} ${material.unit}.`),
    field('Что произошло', kind), field(`Количество, ${material.unit}`, qty), field('Цена прихода', price), field('Объект (для выдачи)', project), field('Примечание', note),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Записать')));
  const dialog = modal('Движение по складу', form);
}

async function historyModal(material) {
  const rows = await api.get(`/materials/${material.id}/movements`);
  modal(`История: ${material.name}`, rows.length
    ? h('div', { class: 'list' }, rows.map((m) => h('div', { class: 'item' }, h('div', { style: 'flex:1' }, h('strong', null, MOVE_KIND[m.kind]), ` ${num(m.quantity)} ${material.unit}`,
      h('p', { class: 'muted' }, [fmtDate(m.moved_on), m.project_name, m.price ? fmtMoney(m.price) : '', m.note].filter(Boolean).join(' · '))))))
    : h('p', { class: 'muted' }, 'Движений пока нет.'));
}

export function requestModal(projectId, materials, onDone) {
  const options = { '': 'Другое (напишу сам)', ...Object.fromEntries(materials.map((m) => [String(m.id), `${m.name} (на складе ${num(m.available)} ${m.unit})`])) };
  const material = select(options, '', { name: 'material_id' });
  const item = h('input', { name: 'item', maxlength: '200', placeholder: 'Название, если нет в списке' });
  const qty = h('input', { name: 'quantity', type: 'number', min: '0', step: 'any' });
  const unit = h('input', { name: 'unit', maxlength: '30', placeholder: 'шт, кг, м3' });
  const need = h('input', { name: 'needed_by', type: 'date' });
  const reason = h('input', { name: 'reason', maxlength: '300', placeholder: 'Для чего нужно (необязательно)' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    const body = { material_id: material.value ? Number(material.value) : null, item: item.value.trim(), quantity: Number(qty.value), unit: unit.value.trim(), needed_by: need.value || null, reason: reason.value.trim() };
    if (!(body.quantity > 0)) { error.textContent = 'Укажите количество.'; error.classList.remove('hidden'); return; }
    try { await api.post(`/projects/${projectId}/material-requests`, body); dialog.close(); toast('Заявка отправлена кладовщику'); onDone(); } catch (err) { showError(error, err); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error, field('Материал', material), field('Или напишите', item),
    h('div', { class: 'row' }, h('div', { style: 'flex:1;min-width:120px' }, field('Количество', qty)), h('div', { style: 'flex:1;min-width:120px' }, field('Единица', unit))),
    field('Нужно к дате', need), field('Зачем', reason), h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Отправить заявку')));
  const dialog = modal('Заявка на материал', form);
}

function requestRow(r, ctx, reload, showProject) {
  const open = ['new', 'partial', 'purchase'].includes(r.status);
  const issue = async () => {
    try { await api.post(`/material-requests/${r.id}/issue`, {}); toast('Выдано'); reload(); } catch (e) { toast(errText(e), 'bad'); }
  };
  const buy = async () => {
    try { await api.post(`/material-requests/${r.id}/to-purchase`); toast('Создана закупка'); reload(); } catch (e) { toast(errText(e), 'bad'); }
  };
  const cancel = async () => {
    try { await api.post(`/material-requests/${r.id}/cancel`); reload(); } catch (e) { toast(errText(e), 'bad'); }
  };
  return h('div', { class: 'item col-item' },
    h('div', { style: 'flex:1;min-width:0' }, h('strong', null, r.item || 'Материал'), ' ', badge(REQUEST_STATUS[r.status], REQUEST_TONE[r.status]),
      r.overdue ? [' ', badge('Просрочена', 'red')] : null, r.shortage ? [' ', badge(`не хватает ${num(r.shortage)}`, 'orange')] : null,
      h('p', { class: 'muted' }, [showProject ? r.project_name : '', `нужно ${num(r.quantity)} ${r.unit}, выдано ${num(r.issued)}`, r.needed_by ? `к ${fmtDate(r.needed_by)}` : '', r.reason].filter(Boolean).join(' · '))),
    h('div', { class: 'row' },
      open && can(ctx, 'materials.issue') ? h('button', { class: 'btn small primary', type: 'button', onclick: issue }, 'Выдать') : null,
      open && !r.purchase_id && can(ctx, 'materials.write') ? h('button', { class: 'btn small', type: 'button', onclick: buy }, 'В закупку') : null,
      open && can(ctx, 'materials.request') ? h('button', { class: 'btn small', type: 'button', onclick: cancel }, 'Отменить') : null));
}

export async function warehousePage(ctx) {
  const page = h('div');
  let view = 'stock';
  async function render() {
    const [materials, requests, projects] = await Promise.all([api.get('/materials'), api.get('/material-requests'), api.get('/projects')]);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const write = can(ctx, 'materials.write');
    const openRequests = requests.filter((r) => ['new', 'partial', 'purchase'].includes(r.status));
    const low = materials.filter((m) => m.low).length;
    const tab = (key, text) => h('button', { class: 'chip' + (view === key ? ' on' : ''), type: 'button', onclick: () => { view = key; render(); } }, text);
    const stock = materials.length ? h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
      h('thead', null, h('tr', null, ['Материал', 'Остаток', 'В резерве', 'Цена', 'На сумму', ''].map((t) => h('th', null, t)))),
      h('tbody', null, materials.map((m) => h('tr', { class: m.low ? 'over' : '' },
        h('td', null, m.name, m.low ? [' ', badge('мало', 'red')] : null), h('td', null, `${num(m.stock)} ${m.unit}`), h('td', null, num(m.reserved)),
        h('td', null, fmtMoney(m.avg_price)), h('td', null, fmtMoney(m.value)),
        h('td', null, h('div', { class: 'row' },
          write ? h('button', { class: 'btn small', type: 'button', onclick: () => moveModal(m, projects, reload) }, 'Движение') : null,
          h('button', { class: 'btn small', type: 'button', onclick: () => historyModal(m) }, 'История'),
          write ? h('button', { class: 'btn small', type: 'button', onclick: () => materialModal(m, reload) }, 'Изменить') : null)))))))
      : h('div', { class: 'empty' }, h('p', null, 'Материалов пока нет. Добавьте то, что хранится на складе: цемент, доска, арматура.'));
    const asks = openRequests.length ? h('div', { class: 'list' }, openRequests.map((r) => requestRow(r, ctx, reload, true))) : h('div', { class: 'empty' }, h('p', null, 'Открытых заявок нет.'));
    page.replaceChildren(
      h('div', { class: 'page-head' }, h('div', null, h('h1', null, 'Склад'), h('p', { class: 'muted' }, `Материалов: ${materials.length}${low ? `, мало: ${low}` : ''}. Открытых заявок: ${openRequests.length}`)),
        write ? h('button', { class: 'btn primary', id: 'add-material', type: 'button', onclick: () => materialModal(null, reload) }, '+ Материал') : null),
      h('div', { class: 'row', style: 'margin-bottom:8px' }, tab('stock', 'Остатки'), tab('requests', `Заявки (${openRequests.length})`)),
      view === 'stock' ? stock : asks);
  }
  await render();
  return page;
}

/** Вкладка объекта: заявки прораба и то, что выдано на объект. */
export async function projectMaterialsTab(box, project, ctx) {
  async function render() {
    const [requests, used, materials] = await Promise.all([api.get(`/projects/${project.id}/material-requests`), api.get(`/projects/${project.id}/materials`), api.get('/materials')]);
    const reload = () => render().catch((e) => toast(errText(e), 'bad'));
    const rows = used.items;
    box.replaceChildren(
      h('div', { class: 'row', style: 'justify-content:space-between;align-items:center;margin-bottom:8px' },
        h('p', { class: 'muted', style: 'margin:0' }, 'Заявки на материалы и то, что уже выдано на объект.'),
        can(ctx, 'materials.request') ? h('button', { class: 'btn primary', id: 'add-request', type: 'button', onclick: () => requestModal(project.id, materials, reload) }, '+ Заявка') : null),
      requests.length ? h('div', { class: 'list' }, requests.map((r) => requestRow(r, ctx, reload, false))) : h('div', { class: 'empty' }, h('p', null, 'Заявок пока нет.')),
      rows.length ? h('section', { class: 'card' }, h('h2', null, 'Выдано на объект'), h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', null, h('tr', null, ['Материал', 'Выдано', 'План по смете', 'На сумму'].map((t) => h('th', null, t)))),
        h('tbody', null, rows.map((m) => h('tr', { class: m.over_plan ? 'over' : '' }, h('td', null, m.name), h('td', null, `${num(m.issued)} ${m.unit}`, m.over_plan ? [' ', badge('больше сметы', 'red')] : null),
          h('td', null, m.planned === null ? '—' : `${num(m.planned)} ${m.unit}`), h('td', null, fmtMoney(m.cost)))))))) : null);
  }
  await render();
}
