// Общие мелочи для сделок: подписи ленты, инициалы, время, итог автоматики.

export const EVENT_LABEL = { created: 'Создана', moved: 'Этап', note: 'Заметка', automation: 'Автоматика' };
export const EVENT_TONE = { created: 'blue', moved: '', note: 'orange', automation: 'green' };

export function initials(name) {
  return (name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}

export function fmtTime(iso) {
  const d = new Date(iso.endsWith('Z') || iso.includes('+') ? iso : iso + 'Z');
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

/**
 * Что сработало автоматически в последнем действии. События идут от новых к старым:
 * берём подряд идущие события автоматики с самого верха. Заметка или задача ставит перед
 * ними своё событие, поэтому после них старые сообщения повторно не показываются.
 */
export function automationNotes(detail) {
  const notes = [];
  for (const e of detail.events) {
    if (e.kind !== 'automation') break;
    notes.push(e.text);
  }
  return notes.reverse();
}
