// Очередь отправки документов без связи. На объекте интернета может не быть:
// документ сохраняется в памяти телефона (IndexedDB) и сам уходит на сервер, когда связь появится.
import { api } from './api.js';

const DB_NAME = 'stroy-offline';
const STORE = 'uploads';
export const QUEUE_EVENT = 'stroy-queue';

function open() {
  return new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) return reject(new Error('no-indexeddb'));
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function run(mode, action) {
  const db = await open();
  try {
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const result = action(tx.objectStore(STORE));
      tx.oncomplete = () => resolve(result && 'result' in result ? result.result : undefined);
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error);
    });
  } finally {
    db.close();
  }
}

function changed() {
  window.dispatchEvent(new Event(QUEUE_EVENT));
}

export async function queueUpload({ projectId, projectName, fields, file }) {
  await run('readwrite', (store) => store.add({ projectId, projectName, fields, file, name: file.name || 'файл', state: 'waiting', error: '', savedAt: Date.now() }));
  changed();
}

export async function pending() {
  return (await run('readonly', (store) => store.getAll())) || [];
}

async function remove(id) {
  await run('readwrite', (store) => store.delete(id));
  changed();
}

async function mark(item, error) {
  await run('readwrite', (store) => store.put({ ...item, state: 'failed', error }));
  changed();
}

export async function discard(id) {
  await remove(id);
}

export async function retry(id) {
  const items = await pending();
  const item = items.find((x) => x.id === id);
  if (item) await run('readwrite', (store) => store.put({ ...item, state: 'waiting', error: '' }));
  changed();
  return flush();
}

let flushing = false;

/** Отправляет всё, что ждёт. Останавливается при отсутствии связи или выходе из аккаунта. */
export async function flush() {
  if (flushing || !navigator.onLine) return { sent: 0, left: (await pending().catch(() => [])).filter((i) => i.state === 'waiting').length };
  flushing = true;
  let sent = 0;
  try {
    for (const item of await pending()) {
      if (item.state !== 'waiting') continue;
      const data = new FormData();
      for (const [key, value] of Object.entries(item.fields)) if (value !== '' && value !== null && value !== undefined) data.append(key, value);
      data.append('file', item.file, item.name);
      try {
        await api.upload(`/projects/${item.projectId}/documents`, data);
        await remove(item.id);
        sent += 1;
      } catch (error) {
        if (error.status === 0 || error.status === 401 || error.status >= 500) break; // нет связи или вход закончился: попробуем позже
        await mark(item, error.code || 'error');
      }
    }
  } finally {
    flushing = false;
  }
  const left = (await pending().catch(() => [])).filter((i) => i.state === 'waiting').length;
  return { sent, left };
}

export function startAutoFlush(onSent) {
  const attempt = async () => {
    try {
      const { sent } = await flush();
      if (sent && onSent) onSent(sent);
    } catch {
      /* очередь недоступна: ничего не делаем */
    }
  };
  window.addEventListener('online', attempt);
  setInterval(attempt, 30000);
  attempt();
}
