// Права: сервер всё равно проверяет каждый запрос, здесь только прячем лишние кнопки и разделы.
export const can = (ctx, cap) => Boolean(ctx.me && ctx.me.can && ctx.me.can.includes(cap));
