# AI Product Image Project (NEW)

Автоматизация AI-обработки изображений для WooCommerce.

- Детекция и удаление логотипов/водяных знаков
- Восстановление изображения (inpainting)
- Удаление фона
- Постобработка и компоновка на шаблон
- Интеграция с WordPress/WooCommerce

## Структура
- `plugins/ai-product-image/` — WordPress-плагин
- `uploads/ai_image/` — задания, результаты, шаблоны, логи
- `py/` — Python-скрипты
- `dataset/` — датасеты для обучения/валидации
- `lama-local/` — inpainting-модель LaMa

Подробности — в файле ai-image-project-context.md 