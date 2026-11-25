import { api } from '../api.js';
import { fmtDate, h, toast } from '../dom.js';
import { icon } from '../icons.js';
import { errText } from '../labels.js';

const POLL_MS = 60000;

function when(iso) {
  if (!iso) return '';
  const d = new Date(/(Z|[+-]\d\d:\d\d)$/.test(iso) ? iso : iso + 'Z');
  return `${fmtDate(iso)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** Колокольчик в верхней панели: число непрочитанных, раз в минуту обновляется. */
export function bellButton() {
  const count = h('span', { class: 'bell-count hidden', 'aria-live': 'polite' });
  const link = h('a', { class: 'bell', href: '#/notifications', title: 'Уведомления', 'aria-label': 'Уведомления' }, icon('bell', 22), count);
  let timer = null;
  async function refresh() {
    if (!document.body.contains(link) && timer) { clearInterval(timer); return; }
    if (document.hidden) return;
    try {
      const { unread } = await api.get('/notifications', { limit: 1 });
      count.textContent = unread > 99 ? '99+' : String(unread);
      count.classList.toggle('hidden', !unread);
      link.setAttribute('aria-label', unread ? `Уведомления, новых: ${unread}` : 'Уведомления');
    } catch { /* нет связи: покажем в следующий раз */ }
  }
  refresh();
  timer = setInterval(refresh, POLL_MS);
  window.addEventListener('notifications-changed', refresh);
  return link;
}

export async function notificationsPage(ctx) {
  const page = h('div');
  async function render() {
    const data = await api.get('/notifications', { limit: 100 });
    const markAll = async () => {
      try { await api.post('/notifications/read', { all: true }); window.dispatchEvent(new Event('notifications-changed')); render(); } catch (e) { toast(errText(e), 'bad'); }
    };
    page.replaceChildren(
      h('div', { class: 'page-head' }, h('div', null, h('h1', null, 'Уведомления'), h('p', { class: 'muted' }, data.unread ? `Новых: ${data.unread}` : 'Всё прочитано')),
        data.unread ? h('button', { class: 'btn small', id: 'read-all', type: 'button', onclick: markAll }, 'Отметить все прочитанными') : null),
      data.items.length ? h('div', { class: 'list' }, data.items.map((n) => h('a', {
        class: 'item notif' + (n.read ? '' : ' unread'), href: n.link || '#/home',
        onclick: async () => { if (!n.read) { try { await api.post('/notifications/read', { ids: [n.id] }); window.dispatchEvent(new Event('notifications-changed')); } catch { /* не страшно */ } } },
      }, h('div', { style: 'flex:1;min-width:0' }, h('strong', null, n.text), h('p', { class: 'muted' }, when(n.created_at)))))) : h('div', { class: 'empty' }, h('p', null, 'Пока ничего нет. Здесь появятся назначенные задачи, приёмка этапов, просрочки и заявки.')),
    );
  }
  await render();
  return page;
}
