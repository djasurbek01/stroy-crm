// Страница заказчика: без входа, только чтение. Видны этапы, сроки и готовность, денег и договоров нет.
import { badge, fmtDate, h } from './dom.js';
import { gantt, scheduleSummary, STAGE_STATUS } from './pages/stages.js';
import { chatWidget } from './pages/chat.js';
import { feedList } from './pages/feed.js';
import { mediaGrid } from './pages/reports.js';
import { PDOC_STATUS, visaChain } from './pages/projdocs.js';
import { taskRow } from './pages/sitetasks.js';

const root = document.getElementById('customer-root');
const token = new URLSearchParams(location.search).get('t') || '';
const TONE = { not_started: '', in_progress: 'blue', done: 'green' };

async function post(path) {
  const response = await fetch(`/public/customer/${encodeURIComponent(token)}${path.url}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(path.body || {}),
  });
  if (!response.ok) throw new Error('bad');
  return response.json();
}

function docsBlock(docs) {
  return h('section', { class: 'offer-block' }, h('h2', null, 'Проектная документация'),
    h('div', { class: 'list' }, docs.map((d) => {
      const active = d.visas.find((v) => v.id === d.active_visa_id);
      const mine = active && active.side === 'customer' ? active : null;
      const name = h('input', { placeholder: 'Ваше имя', 'aria-label': 'Ваше имя', maxlength: '120' });
      const comment = h('input', { placeholder: 'Комментарий (необязательно)', 'aria-label': 'Комментарий к визе', maxlength: '1000' });
      const status = h('p', { class: 'muted', role: 'status' });
      const decide = (decision) => async (event) => {
        event.currentTarget.disabled = true;
        try { await post({ url: `/docs/${d.id}/visas/${mine.id}/decide`, body: { decision, name: name.value, comment: comment.value } }); await load(); }
        catch { status.textContent = 'Не получилось отправить. Обновите страницу и попробуйте ещё раз.'; event.currentTarget.disabled = false; }
      };
      return h('div', { class: 'item col-item' },
        h('div', { style: 'flex:1;min-width:0' },
          h('strong', null, d.title), ' ', badge(PDOC_STATUS[d.status]), ' ', badge(`версия ${d.version}`),
          h('p', { class: 'muted' }, [d.section, d.released_at ? `передано в работу ${fmtDate(d.released_at)}` : ''].filter(Boolean).join(' · ')),
          visaChain(d),
          mine ? h('div', { class: 'offer-accept no-print' }, h('h2', null, 'Ваша виза'), name, comment,
            h('div', { class: 'actions' }, h('button', { class: 'btn good', type: 'button', onclick: decide('approved') }, 'Согласовать'), h('button', { class: 'btn bad', type: 'button', onclick: decide('rejected') }, 'Отклонить')), status) : null,
        ),
        h('a', { class: 'btn small', href: d.url }, 'Скачать'),
      );
    })),
  );
}

function tasksBlock(tasks) {
  const name = h('input', { placeholder: 'Ваше имя', 'aria-label': 'Ваше имя', autocomplete: 'name', maxlength: '120' });
  const title = h('input', { placeholder: 'Что хотите сообщить или попросить', 'aria-label': 'Сообщение подрядчику', maxlength: '300' });
  const message = h('p', { class: 'muted', role: 'status' });
  const send = h('button', { class: 'btn primary', id: 'customer-send', type: 'button', onclick: async (event) => {
    if (!title.value.trim()) { message.textContent = 'Напишите сообщение.'; return; }
    event.currentTarget.disabled = true;
    try {
      await post({ url: '/tasks', body: { title: title.value, author: name.value } });
      await load();
    } catch {
      message.textContent = 'Не получилось отправить. Попробуйте ещё раз.';
      event.currentTarget.disabled = false;
    }
  } }, 'Отправить подрядчику');
  return h('section', { class: 'offer-block' }, h('h2', null, 'Задачи и предписания'),
    tasks.length ? h('div', { class: 'list' }, tasks.map((t) => taskRow(t, t.side === 'customer' && !t.done
      ? h('button', { class: 'btn small', type: 'button', onclick: async (event) => {
        event.currentTarget.disabled = true;
        try { await post({ url: `/tasks/${t.id}/done` }); await load(); } catch { event.currentTarget.disabled = false; }
      } }, 'Выполнено') : null)))
      : h('p', { class: 'muted' }, 'Пока задач нет.'),
    h('div', { class: 'offer-accept no-print' }, h('h2', null, 'Поставить задачу подрядчику'), h('p', { class: 'muted' }, 'Просьба появится в списке его задач. Для быстрого разговора используйте чат ниже.'), name, title, send, message),
  );
}

function customerChat() {
  const name = h('input', { placeholder: 'Ваше имя (увидит подрядчик)', 'aria-label': 'Ваше имя в чате', maxlength: '120', autocomplete: 'name', value: localStorage.getItem('customer_name') || '' });
  name.addEventListener('change', () => { try { localStorage.setItem('customer_name', name.value); } catch { /* приватный режим */ } });
  const base = `/public/customer/${encodeURIComponent(token)}/chat`;
  const widget = chatWidget({
    load: async (after) => (await (await fetch(`${base}?after=${after}`)).json()).messages,
    send: async (body) => {
      const response = await fetch(base, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ body, name: name.value }) });
      if (!response.ok) throw new Error('bad');
      return response.json();
    },
    isMine: (m) => m.side === 'customer',
  });
  return h('div', null, name, widget);
}

function render(data) {
  const { company, project, stages, overall, tasks, reports, docs, activity, camera, schedule } = data;
  const chart = gantt(stages);
  root.replaceChildren(
    h('header', { class: 'offer-head' },
      h('div', null, h('h1', null, project.name), h('p', { class: 'muted' }, project.address || 'Ход строительства')),
      h('div', { class: 'offer-company' }, h('strong', null, company.name), company.phone ? h('div', null, company.phone) : null, company.email ? h('div', null, company.email) : null),
    ),
    h('section', { class: 'offer-block' },
      h('h2', null, `Готовность объекта: ${overall}%`),
      h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': String(overall), 'aria-valuemin': '0', 'aria-valuemax': '100' },
        h('div', { class: 'progress-fill' + (overall >= 100 ? ' done' : ''), style: `width:${overall}%` })),
      schedule ? scheduleSummary(schedule) : null,
    ),
    stages.length ? null : h('p', { class: 'muted' }, 'График пока не составлен. Подрядчик добавит этапы, и они появятся здесь.'),
    chart ? h('section', { class: 'offer-block' }, h('h2', null, 'График работ'), chart) : null,
    stages.length ? h('section', { class: 'offer-block' }, h('h2', null, 'Этапы'),
      h('div', { class: 'list' }, stages.map((s) => h('div', { class: 'item' },
        h('div', { style: 'flex:1;min-width:0' },
          h('strong', null, s.name), ' ', badge(STAGE_STATUS[s.status], TONE[s.status]), s.accepted ? [' ', badge('Принят', 'green')] : null, s.overdue ? [' ', badge('Задерживается', 'red')] : null,
          h('p', { class: 'muted' }, s.start_date && s.end_date ? `${fmtDate(s.start_date)} — ${fmtDate(s.end_date)}` : 'Сроки уточняются', ` · готово ${s.percent}%`, s.note ? ` · ${s.note}` : ''),
        ),
      ))),
    ) : null,
    (reports || []).length ? h('section', { class: 'offer-block' }, h('h2', null, 'Фото и видео с объекта'), mediaGrid(reports, stages, null)) : null,
    camera ? h('section', { class: 'offer-block' }, h('h2', null, 'Камера на объекте'), h('p', { class: 'muted' }, camera.label),
      h('a', { class: 'btn primary', href: camera.url, target: '_blank', rel: 'noopener noreferrer' }, 'Смотреть трансляцию')) : null,
    (docs || []).length ? docsBlock(docs) : null,
    tasksBlock(tasks || []),
    h('section', { class: 'offer-block' }, h('h2', null, 'Чат с подрядчиком'), customerChat()),
    h('section', { class: 'offer-block' }, h('h2', null, 'Лента событий'), feedList(activity || [])),
  );
}

async function load() {
  try {
    const response = await fetch(`/public/customer/${encodeURIComponent(token)}`);
    if (response.status === 404) {
      root.replaceChildren(h('h1', null, 'Ссылка не найдена'), h('p', { class: 'muted' }, 'Ссылка устарела или была отключена. Попросите подрядчика прислать новую.'));
      return;
    }
    if (!response.ok) throw new Error('bad');
    render(await response.json());
  } catch {
    root.replaceChildren(h('h1', null, 'Не получилось загрузить'), h('p', { class: 'muted' }, 'Проверьте интернет и обновите страницу.'));
  }
}

load();
