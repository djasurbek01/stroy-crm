import { api } from '../api.js';
import { badge, field, h, modal, select } from '../dom.js';
import { PROJECT_STATUS, errText } from '../labels.js';
import { can } from '../perm.js';

function statCards(item) {
  const stats = [badge(`Документов: ${item.documents}`)];
  if (item.overdue) stats.push(badge(`Просрочено: ${item.overdue}`, 'red'));
  if (item.in_review) stats.push(badge(`На согласовании: ${item.in_review}`, 'orange'));
  if (item.purchases_open) stats.push(badge(`Закупок в работе: ${item.purchases_open}`, 'blue'));
  return stats;
}

export function addProjectModal(onCreated) {
  const form = h(
    'form',
    { novalidate: true },
    h('div', { class: 'error hidden', role: 'alert' }),
    field('Название объекта', h('input', { name: 'name', placeholder: 'Например: Дом на Ленина', required: true })),
    field('Адрес', h('input', { name: 'address', placeholder: 'Город, улица, дом' })),
    field('Стадия', select(PROJECT_STATUS, 'planning', { name: 'status' })),
    h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Создать объект')),
  );
  const dialog = modal('Новый объект', form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = form.querySelector('.error');
    errorBox.classList.add('hidden');
    const name = form.elements.name.value.trim();
    if (!name) {
      errorBox.textContent = 'Напишите название объекта.';
      errorBox.classList.remove('hidden');
      return;
    }
    try {
      const project = await api.post('/projects', {
        name,
        address: form.elements.address.value.trim(),
        status: form.elements.status.value,
      });
      dialog.close();
      onCreated(project);
    } catch (error) {
      errorBox.textContent = errText(error);
      errorBox.classList.remove('hidden');
    }
  });
}

export async function projectsPage(ctx) {
  const items = await api.get('/dashboard');
  const page = h('div');

  page.append(
    h(
      'div',
      { class: 'page-head' },
      h(
        'div',
        null,
        h('h1', { class: 'page-title' }, 'Объекты'),
        h('p', { class: 'muted' }, 'Один объект — одна стройка. Внутри: документы, договоры и закупки.'),
      ),
      can(ctx, 'projects.write')
        ? h('button', { class: 'btn primary', id: 'add-project', type: 'button', onclick: () => addProjectModal((p) => ctx.go(`#/project/${p.id}`)) }, '+ Добавить объект')
        : null,
    ),
  );

  if (!items.length) {
    page.append(
      h(
        'div',
        { class: 'empty' },
        h('h2', null, 'Пока нет ни одного объекта'),
        h('p', { class: 'muted' }, 'Создайте первый объект или посмотрите, как всё выглядит на готовом примере.'),
        h(
          'div',
          { class: 'row' },
          can(ctx, 'projects.write') ? h('button', { class: 'btn primary', type: 'button', onclick: () => addProjectModal((p) => ctx.go(`#/project/${p.id}`)) }, 'Создать объект') : null,
          h('button', { class: 'btn', type: 'button', onclick: () => ctx.startTour() }, 'Показать на примере'),
        ),
      ),
    );
    return page;
  }

  page.append(
    h(
      'div',
      { class: 'grid project-list' },
      items.map((item) =>
        h(
          'a',
          { class: 'card', href: `#/project/${item.project_id}` },
          h('h3', null, item.name),
          h('p', { class: 'muted' }, item.address || 'Адрес не указан'),
          h('div', { class: 'stats' }, badge(PROJECT_STATUS[item.status] || item.status), statCards(item)),
        ),
      ),
    ),
  );
  return page;
}
