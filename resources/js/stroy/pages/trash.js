// Корзина (только владелец): удалённое хранится 30 дней, потом исчезает навсегда.
import { api } from '../api.js';
import { badge, h, toast } from '../dom.js';
import { fmtTime } from '../deal-util.js';
import { errText } from '../labels.js';

export async function trashPage(ctx) {
  const items = await api.get('/trash');
  const list = h('div', { class: 'list' });

  function draw(rows) {
    list.replaceChildren(
      ...(rows.length
        ? rows.map((t) =>
            h(
              'div',
              { class: 'item' },
              h('div', null, h('div', { class: 'title' }, t.title || 'Без названия'), h('div', { class: 'meta' }, `Удалено ${fmtTime(t.deleted_at)}`)),
              h(
                'div',
                { class: 'side-info' },
                badge(t.kind),
                h('button', {
                  class: 'btn small primary',
                  type: 'button',
                  onclick: async () => {
                    try {
                      await api.post(`/trash/${t.entity}/${t.id}/restore`);
                      toast('Восстановлено');
                      draw(rows.filter((x) => !(x.entity === t.entity && x.id === t.id)));
                    } catch (error) {
                      toast(errText(error), 'bad');
                    }
                  },
                }, 'Восстановить'),
              ),
            ),
          )
        : [h('div', { class: 'empty' }, h('h2', null, 'Корзина пуста'), h('p', { class: 'muted' }, 'Удалённые сделки, документы, договоры, закупки и контакты появятся здесь.'))]),
    );
  }
  draw(items);
  return h(
    'div',
    null,
    h('div', { class: 'page-head' }, h('div', null, h('h1', { class: 'page-title' }, 'Корзина'), h('p', { class: 'muted' }, 'Удалённое хранится 30 дней, потом исчезает навсегда. Пока оно здесь, его можно вернуть.'))),
    list,
  );
}
