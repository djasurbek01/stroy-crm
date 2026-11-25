<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Оставить заявку</title>
  @vite(['resources/css/app.css', 'resources/js/stroy/lead.js'])
</head>
<body class="lead-body">
  <main class="lead-card">
    <form id="lead-form" novalidate>
      <h1>Оставьте заявку</h1>
      <p class="muted">Расскажите, что нужно построить. Мы перезвоним и всё обсудим.</p>
      <div id="lead-error" class="error hidden" role="alert"></div>
      <label class="field"><span>Как к вам обращаться</span><input name="name" autocomplete="name"></label>
      <label class="field"><span>Телефон</span><input name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7"></label>
      <label class="field"><span>Что нужно построить</span><textarea name="message" rows="4"></textarea></label>
      <div class="hp" aria-hidden="true"><label>Сайт<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <button class="btn primary block" type="submit">Отправить заявку</button>
    </form>
    <div id="lead-done" class="hidden">
      <h1>Спасибо!</h1>
      <p>Заявка получена. Мы свяжемся с вами в ближайшее время.</p>
    </div>
  </main>
</body>
</html>
