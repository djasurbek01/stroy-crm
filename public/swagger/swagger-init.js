// Запуск Swagger UI. Лежит отдельным файлом, потому что защита страницы (CSP) не пускает встроенные скрипты.
window.ui = SwaggerUIBundle({
  url: '/openapi.json',
  dom_id: '#swagger-ui',
  deepLinking: true,
  docExpansion: 'none',
  defaultModelsExpandDepth: 0,
  displayRequestDuration: true,
  filter: true,
  tryItOutEnabled: true,
  persistAuthorization: true,
});
