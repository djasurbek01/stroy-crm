import { api } from '../api.js';
import { badge, field, h, modal, select, toast } from '../dom.js';
import { icon } from '../icons.js';
import { PROJECT_STATUS, ROLES, errText } from '../labels.js';
import { can } from '../perm.js';
import { contractsTab } from './contracts.js';
import { documentsTab } from './documents.js';
import { purchasesTab } from './purchases.js';
import { scheduleTab } from './stages.js';
import { siteTasksTab } from './sitetasks.js';
import { reportsTab } from './reports.js';
import { projDocsTab } from './projdocs.js';
import { chatTab } from './chat.js';
import { feedTab } from './feed.js';
import { qualityTab } from './quality.js';
import { financeTab } from './finance.js';
import { estimatesTab } from './estimates.js';
import { projectMaterialsTab } from './warehouse.js';

const TABS = {
  schedule: ['График', scheduleTab],
  tasks: ['Задачи', siteTasksTab],
  reports: ['Фото и видео', reportsTab],
  projdocs: ['Проект', projDocsTab],
  chat: ['Чат', chatTab],
  feed: ['Лента', feedTab],
  quality: ['Приёмка', qualityTab, 'inspections.read'],
  documents: ['Документы', documentsTab],
  contracts: ['Договоры', contractsTab],
  purchases: ['Закупки', purchasesTab],
  materials: ['Материалы', projectMaterialsTab, 'materials.read'],
  estimates: ['Смета', estimatesTab, 'estimates.read'],
  finance: ['Финансы', financeTab, 'finance.read'],
};

const VIA = { owner: 'видит все объекты', manager: 'руководитель проекта', foreman: 'прораб объекта', member: 'участник' };

/** Кто видит объект. Остальные (кроме владельца) его не видят и в него не попадают. */
async function membersDialog(project, ctx) {
  const [members, team] = await Promise.all([api.get(`/projects/${project.id}/members`), can(ctx, 'projects.write') ? api.get('/team') : Promise.resolve([])]);
  const body = h('div');
  const dialog = modal('Участники объекта', body);
  const list = h('div', { class: 'list' }, members.map((m) => h('div', { class: 'item' },
    h('div', null, h('div', { class: 'title' }, m.name), h('div', { class: 'meta' }, `${m.role_name} · ${VIA[m.via] || ''}`)))));
  body.append(h('p', { class: 'muted' }, 'Объект видят только его участники и владелец компании. Подрядчики и ген. директора других объектов сюда не попадут.'), list);
  if (!can(ctx, 'projects.write')) return;
  const memberIds = new Set(members.filter((m) => m.via === 'member').map((m) => m.id));
  const fixed = new Set(members.filter((m) => m.via !== 'member').map((m) => m.id));
  const boxes = team.filter((u) => !fixed.has(u.id)).map((u) => {
    const input = h('input', { type: 'checkbox', value: String(u.id), checked: memberIds.has(u.id) });
    return h('label', { class: 'check' }, input, h('span', null, u.name, ' ', h('span', { class: 'muted' }, ROLES[u.role] || u.role)));
  });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  body.append(
    h('h3', { class: 'pane-title' }, 'Кому открыть объект'),
    boxes.length ? boxes : h('p', { class: 'muted' }, 'Добавьте сотрудников и подрядчиков в разделе «Команда».'),
    error,
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', id: 'save-members', type: 'button', onclick: async () => {
      const ids = boxes.map((b) => b.querySelector('input')).filter((i) => i.checked).map((i) => Number(i.value));
      try { await api.put(`/projects/${project.id}/members`, { user_ids: ids }); dialog.close(); toast('Участники сохранены'); }
      catch (e) { error.textContent = errText(e); error.classList.remove('hidden'); }
    } }, 'Сохранить')),
  );
}

async function editProject(project, ctx) {
  const people = await api.get('/team');
  const who = { '': 'Не назначен', ...Object.fromEntries(people.map((m) => [String(m.id), m.name])) };
  const name = h('input', { name: 'name', maxlength: '200', value: project.name });
  const address = h('input', { name: 'address', maxlength: '300', value: project.address || '' });
  const status = select(PROJECT_STATUS, project.status, { name: 'status' });
  const manager = select(who, project.manager_id ? String(project.manager_id) : '', { name: 'manager_id' });
  const foreman = select(who, project.foreman_id ? String(project.foreman_id) : '', { name: 'foreman_id' });
  const contract = h('input', { name: 'contract_value', type: 'number', min: '0', step: '1000', value: project.contract_value ? String(project.contract_value) : '' });
  const error = h('div', { class: 'error hidden', role: 'alert' });
  const submit = async (e) => {
    e.preventDefault();
    const num = (v) => (v ? Number(v) : null);
    const body = { name: name.value.trim(), address: address.value.trim(), status: status.value, manager_id: num(manager.value), foreman_id: num(foreman.value) };
    if (contract.value !== '') body.contract_value = Number(contract.value);
    if (!body.name) { error.textContent = 'Напишите название объекта.'; error.classList.remove('hidden'); return; }
    try { await api.patch(`/projects/${project.id}`, body); dialog.close(); toast('Сохранено'); ctx.go(location.hash); } catch (err) { error.textContent = errText(err); error.classList.remove('hidden'); }
  };
  const form = h('form', { novalidate: true, onsubmit: submit }, error, field('Название', name), field('Адрес', address), field('Стадия', status),
    field('Руководитель проекта', manager), field('Прораб', foreman), can(ctx, 'finance.read') ? field('Стоимость по договору, ₽', contract) : null,
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Сохранить')));
  const dialog = modal('Объект', form);
}

function confirmDeleteDemo(ctx) {
  const body = h(
    'div',
    null,
    h('p', null, 'Демо-объект и все его примеры будут удалены. Ваши настоящие объекты не затронуты.'),
    h(
      'div',
      { class: 'actions' },
      h(
        'button',
        {
          class: 'btn bad',
          type: 'button',
          onclick: async () => {
            try {
              await api.del('/demo');
              dialog.close();
              toast('Демо удалено');
              ctx.go('#/objects');
            } catch (error) {
              toast(errText(error), 'bad');
            }
          },
        },
        'Удалить демо',
      ),
      h('button', { class: 'btn', type: 'button', onclick: () => dialog.close() }, 'Оставить'),
    ),
  );
  const dialog = modal('Удалить демо-объект?', body);
}

/** Вкладки объекта, собранные в 4 понятные группы. Адреса вкладок не меняются. */
const TAB_GROUPS = [
  ['work', 'Ход работ', 'tasks', ['schedule', 'tasks', 'quality', 'reports']],
  ['docs', 'Документы', 'plans', ['documents', 'projdocs', 'contracts']],
  ['money', 'Деньги и материалы', 'money', ['purchases', 'materials', 'estimates', 'finance']],
  ['talk', 'Общение', 'contacts', ['chat', 'feed']],
];

export async function projectPage(ctx, id, tab) {
  const project = await api.get(`/projects/${id}`);
  const tabs = Object.entries(TABS).filter(([, [, , cap]]) => !cap || can(ctx, cap));
  const allowed = new Set(tabs.map(([key]) => key));
  const groups = TAB_GROUPS.map(([key, title, iconName, keys]) => [key, title, iconName, keys.filter((k) => allowed.has(k))]).filter(([, , , keys]) => keys.length);
  const current = allowed.has(tab) ? tab : groups[0][3][0];
  const group = groups.find(([, , , keys]) => keys.includes(current));
  const page = h('div');

  page.append(
    h('a', { href: '#/objects', class: 'muted' }, '← Все объекты'),
    h(
      'div',
      { class: 'page-head', style: 'margin-top:8px' },
      h(
        'div',
        null,
        h('h1', null, project.name),
        h(
          'p',
          { class: 'muted' },
          project.address || 'Адрес не указан',
          ' ',
          badge(PROJECT_STATUS[project.status] || project.status),
          ' ',
          project.is_demo ? badge('Демо', 'orange') : null,
        ),
      ),
      h(
        'div',
        { class: 'row' },
        h('button', { class: 'btn small', id: 'project-members', type: 'button', onclick: () => membersDialog(project, ctx).catch((e) => toast(errText(e), 'bad')) }, 'Участники'),
        can(ctx, 'projects.write') ? h('button', { class: 'btn small', id: 'edit-project', type: 'button', onclick: () => editProject(project, ctx).catch((e) => toast(errText(e), 'bad')) }, 'Изменить') : null,
        can(ctx, 'money.read') ? h('a', { class: 'btn small', href: `/export.xlsx?project_id=${id}` }, 'Выгрузить для 1С') : null,
        project.is_demo && can(ctx, 'demo.manage')
          ? h('button', { class: 'btn small', type: 'button', onclick: () => confirmDeleteDemo(ctx) }, 'Удалить демо')
          : null,
      ),
    ),
    h(
      'nav',
      { class: 'tab-groups', 'aria-label': 'Разделы объекта' },
      groups.map(([key, title, iconName, keys]) =>
        h(
          'a',
          { class: 'tab-group' + (group[0] === key ? ' active' : ''), id: `group-${key}`, href: `#/project/${id}/${keys[0]}`, 'aria-current': group[0] === key ? 'true' : null },
          h('span', { class: 'tab-group-icon' }, icon(iconName, 20)),
          h('span', { class: 'tab-group-text' }, h('strong', null, title), h('small', null, keys.map((k) => TABS[k][0]).join(' · '))),
        ),
      ),
    ),
    group[3].length > 1
      ? h(
          'nav',
          { class: 'tabs', 'aria-label': group[1] },
          group[3].map((key) =>
            h('a', { class: 'tab' + (key === current ? ' active' : ''), id: `tab-${key}`, href: `#/project/${id}/${key}` }, TABS[key][0]),
          ),
        )
      : null,
  );

  const box = h('div');
  page.append(box);
  await TABS[current][1](box, project, ctx);
  // на телефоне вкладок больше, чем влезает: показываем активную, остальные видны по прокрутке
  setTimeout(() => { const active = page.querySelector('.tab.active'); if (active) active.scrollIntoView({ inline: 'center', block: 'nearest' }); }, 0);
  return page;
}
