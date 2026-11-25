// «Неразобранное»: заявки с сайта и из Telegram, которые ждут решения. Принять или отклонить.
import { api } from '../api.js';
import { fmtTime } from '../deal-util.js';
import { badge, confirmDialog, field, h, modal, select, toast } from '../dom.js';
import { errText } from '../labels.js';

const SOURCE = { site: 'Сайт', telegram: 'Telegram', manual: 'Вручную' };

export function unsortedBlock(ctx, team, clients, onChange) {
  const box = h('section', { class: 'unsorted hidden', id: 'unsorted' });

  async function load() {
    const { items } = await api.page('/deals', { unsorted: 'true', limit: 100 });
    if (!items.length) {
      box.classList.add('hidden');
      return;
    }
    box.classList.remove('hidden');
    box.replaceChildren(
      h('div', { class: 'unsorted-head' }, h('h2', { style: 'margin:0' }, `Неразобранное: ${items.length}`), h('span', { class: 'muted' }, 'Новые заявки. Примите в работу или отклоните.')),
      ...items.map((d) =>
        h(
          'div',
          { class: 'item', 'data-lead': String(d.id) },
          h('div', null, h('div', { class: 'title' }, d.title), h('div', { class: 'meta' }, `${clients[d.counterparty_id] || ''} · ${fmtTime(d.created_at)}`)),
          h(
            'div',
            { class: 'side-info' },
            badge(SOURCE[d.source] || d.source, d.source === 'telegram' ? 'blue' : 'orange'),
            h('button', { class: 'btn small', type: 'button', onclick: () => ctx.go(`#/deal/${d.id}`) }, 'Открыть'),
            h('button', { class: 'btn small primary accept-lead', type: 'button', onclick: () => acceptModal(d) }, 'Принять'),
            h('button', { class: 'btn small bad', type: 'button', onclick: () => rejectModal(d) }, 'Отклонить'),
          ),
        ),
      ),
    );
  }

  function acceptModal(deal) {
    const options = {};
    for (const m of team) options[m.id] = m.name;
    const form = h(
      'form',
      { novalidate: true },
      h('p', null, `Заявка «${deal.title}» попадёт на доску в первый этап воронки.`),
      field('Кто ведёт', select(options, String(ctx.me.id), { name: 'responsible_id' })),
      h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'submit' }, 'Принять в работу')),
    );
    const dialog = modal('Принять заявку', form);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api.post(`/deals/${deal.id}/accept`, { responsible_id: Number(form.elements.responsible_id.value) });
        dialog.close();
        toast('Заявка принята');
        await load();
        onChange();
      } catch (error) {
        toast(errText(error), 'bad');
      }
    });
  }

  function rejectModal(deal) {
    const form = h(
      'form',
      { novalidate: true },
      h('p', null, `Заявка «${deal.title}» уйдёт в «Отказ». Её можно будет найти в аналитике.`),
      field('Причина (необязательно)', h('input', { name: 'reason', placeholder: 'Например: не наш район' })),
      h('div', { class: 'actions' }, h('button', { class: 'btn bad', type: 'submit' }, 'Отклонить')),
    );
    const dialog = modal('Отклонить заявку', form);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api.post(`/deals/${deal.id}/reject`, { reason: form.elements.reason.value.trim() });
        dialog.close();
        toast('Заявка отклонена');
        await load();
        onChange();
      } catch (error) {
        toast(errText(error), 'bad');
      }
    });
  }

  return { element: box, load };
}

export async function leadSettingsModal() {
  const body = h('div');
  const dialog = modal('Приём заявок', body);

  async function draw() {
    const cfg = await api.get('/leads/settings');
    const field_ = (label, value, hint) =>
      h(
        'label',
        { class: 'field' },
        h('span', null, label),
        h('input', { value, readonly: true, onfocus: (e) => e.target.select(), 'aria-label': label }),
        hint ? h('p', { class: 'hint' }, hint) : null,
      );
    const absolute = (path) => (path ? location.origin + path : '');
    body.replaceChildren(
      h('p', null, 'Клиент заполняет форму, а заявка сама попадает в «Неразобранное» на странице сделок.'),
      field_('Ссылка на форму заявки', cfg.form_url || absolute(cfg.form_path), 'Отправьте её клиенту или поставьте на сайт кнопкой.'),
      field_('Код для вставки на сайт', cfg.iframe.replace('src="/', `src="${location.origin}/`), 'Вставьте этот код в нужное место страницы вашего сайта.'),
      cfg.telegram_url ? field_('Ссылка на бота в Telegram', cfg.telegram_url, 'Клиент нажимает «Запустить» и пишет, что нужно построить.') : h('p', { class: 'muted' }, 'Приём заявок через Telegram включится, когда будет подключён бот.'),
      h('div', { class: 'actions' },
        h('a', { class: 'btn', href: cfg.form_path, target: '_blank', rel: 'noopener' }, 'Открыть форму'),
        h('button', { class: 'btn bad', id: 'rotate-key', type: 'button', onclick: () => confirmDialog('Заменить ссылку?', 'Старая ссылка и старый код на сайте перестанут работать. Это нужно, если ссылку выложили не туда.', 'Заменить', async () => { try { await api.post('/leads/settings/rotate'); toast('Ссылка заменена'); draw(); } catch (error) { toast(errText(error), 'bad'); } }) }, 'Заменить ссылку'),
      ),
    );
  }
  await draw();
  return dialog;
}
