import { api } from '../api.js';
import { fmtDate, h } from '../dom.js';

const KIND_LABEL = { stage: 'График', task: 'Задачи', report: 'Фото и видео', doc: 'Документы' };

/** Список событий. Общий для кабинета подрядчика и страницы заказчика. */
export function feedList(events) {
  if (!events.length) return h('p', { class: 'muted' }, 'Событий пока нет. Здесь появится всё, что происходит на объекте.');
  return h('ul', { class: 'feed' }, events.map((e) => h('li', { class: 'feed-row' },
    h('span', { class: 'feed-kind' }, KIND_LABEL[e.kind] || 'Объект'),
    h('div', { style: 'flex:1;min-width:0' }, e.text, h('div', { class: 'muted feed-meta' }, [e.actor, fmtDate(e.created_at)].filter(Boolean).join(' · '))),
  )));
}

export async function feedTab(box, project) {
  box.replaceChildren(h('p', { class: 'muted' }, 'Что происходит на объекте: этапы, задачи, фото, документы. То же видит заказчик.'), feedList(await api.get(`/projects/${project.id}/activity`)));
}
