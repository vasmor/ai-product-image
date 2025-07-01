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

## Важно: весовой файл U^2-Net
Для работы автоматического выделения маски объекта требуется файл весов `py/u2net.pth` (размер ~170 МБ).

Скачайте его вручную с официального репозитория: https://github.com/xuebinqin/U-2-Net/releases

Поместите файл в папку `py/` рядом с кодом. 