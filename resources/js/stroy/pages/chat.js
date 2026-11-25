import { api } from '../api.js';
import { fmtDate, h, toast } from '../dom.js';
import { errText } from '../labels.js';

const POLL_MS = 8000;

function timeOf(iso) {
  if (!iso) return '';
  const d = new Date(/(Z|[+-]\d\d:\d\d)$/.test(iso) ? iso : iso + 'Z');  // сервер отдаёт то с поясом, то без
  return `${fmtDate(iso)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** Окно чата. Общее для кабинета подрядчика и страницы заказчика.
 *  load(after) отдаёт новые сообщения, send(text) отправляет, isMine(message) решает, с какой стороны рисовать. */
export function chatWidget({ load, send, isMine, onSeen }) {
  const list = h('div', { class: 'chat-list', role: 'log', 'aria-live': 'polite', 'aria-label': 'Переписка' });
  const input = h('textarea', { rows: '2', maxlength: '2000', placeholder: 'Напишите сообщение', 'aria-label': 'Сообщение' });
  const button = h('button', { class: 'btn primary', type: 'submit', id: 'chat-send' }, 'Отправить');
  const form = h('form', { class: 'chat-form', novalidate: true }, input, button);
  const root = h('div', { class: 'chat' }, list, form);
  let lastId = 0;
  let timer = null;
  let attached = false;

  function add(messages) {
    if (!messages.length) return;
    const stick = list.scrollHeight - list.scrollTop - list.clientHeight < 80 || lastId === 0;
    for (const m of messages) {
      if (m.id <= lastId) continue;
      lastId = m.id;
      list.append(h('div', { class: 'chat-msg ' + (isMine(m) ? 'mine' : 'theirs') },
        h('div', { class: 'chat-meta' }, m.author, ' · ', timeOf(m.created_at)),
        h('div', { class: 'chat-body' }, m.body)));
    }
    if (stick) list.scrollTop = list.scrollHeight;
    if (onSeen) onSeen(lastId);
  }

  async function poll() {
    if (document.body.contains(root)) attached = true;
    else if (attached) { clearInterval(timer); return; }  // вкладку закрыли
    if (document.hidden) return;
    try { add(await load(lastId)); } catch { /* сеть моргнула, следующий опрос догонит */ }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    button.disabled = true;
    try {
      add([await send(text)]);
      input.value = '';
      input.focus();
    } catch (e) {
      toast(errText(e), 'bad');
    } finally {
      button.disabled = false;
    }
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) form.requestSubmit();
  });

  poll().then(() => {
    if (!lastId) list.append(h('p', { class: 'muted chat-empty' }, 'Сообщений пока нет. Напишите первым.'));
  });
  timer = setInterval(poll, POLL_MS);
  const clearEmpty = new MutationObserver(() => { const e = list.querySelector('.chat-empty'); if (e && list.querySelector('.chat-msg')) e.remove(); });
  clearEmpty.observe(list, { childList: true });
  return root;
}

export async function chatTab(box, project, ctx) {
  box.replaceChildren(h('p', { class: 'muted' }, 'Переписка подрядчика и заказчика по этому объекту. Заказчик пишет из своей ссылки.'));
  const widget = chatWidget({
    load: async (after) => (await api.get(`/projects/${project.id}/chat`, { after })).messages,
    send: (body) => api.post(`/projects/${project.id}/chat`, { body }),
    isMine: (m) => m.side === 'staff',
    onSeen: (id) => { api.post(`/projects/${project.id}/chat/seen`, { last_id: id }).catch(() => {}); },
  });
  box.append(widget);
}
