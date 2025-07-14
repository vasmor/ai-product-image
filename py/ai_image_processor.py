import os
import json
from loguru import logger
from pathlib import Path
import yaml
from jsonschema import validate, ValidationError
# from rembg import remove  # Удаляем импорт локального rembg
from PIL import Image, ImageDraw, ImageFont
import cv2
import numpy as np
import sys
import argparse
import subprocess
import shutil
import uuid
import io
import requests
import importlib.util
import base64
import time  # Добавлен импорт для паузы между задачами

# --- Явная проверка Python 3.9 и активация venv39 (только для Windows) ---
if not (sys.version_info.major == 3 and sys.version_info.minor == 9):
    venv_python = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'venv39', 'Scripts', 'python.exe')
    if os.path.exists(venv_python):
        print(f"[PYTHON ENV CHECK] Перезапуск в окружении Python 3.9: {venv_python}")
        os.execv(venv_python, [venv_python] + sys.argv)
    else:
        print("[PYTHON ENV CHECK] Требуется Python 3.9 и окружение venv39! Завершение работы.")
        sys.exit(1)

print('=== ai_image_processor.py STARTED ===')
print('sys.argv:', sys.argv)

# --- Парсинг аргументов ---
parser = argparse.ArgumentParser()
parser.add_argument('--config', type=str, default='config.yaml', help='Путь к config.yaml')
parser.add_argument('--task', type=str, help='Путь к задаче (JSON)')
parser.add_argument('--debug', action='store_true', help='Включить подробное логирование и сохранение промежуточных изображений')
args, unknown = parser.parse_known_args()

CONFIG_PATH = Path(args.config)
if CONFIG_PATH.exists():
    with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)
else:
    raise RuntimeError(f'config.yaml not found: {CONFIG_PATH}')

PROJECT_ROOT = Path(__file__).parent.parent.resolve()
TASKS_DIR = (PROJECT_ROOT / config['tasks_dir']).resolve()
RESULTS_DIR = (PROJECT_ROOT / config['results_dir']).resolve()
LOGS_DIR = (PROJECT_ROOT / config['logs_dir']).resolve()
ORIGINALS_DIR = (PROJECT_ROOT / config['originals_dir']).resolve()
PROCESSED_DIR = (PROJECT_ROOT / config['processed_dir']).resolve()
TEMPLATES_DIR = (PROJECT_ROOT / config['templates_dir']).resolve()
# LOGOS_DIR = (PROJECT_ROOT / config['logos_dir']).resolve()  # удалено как неиспользуемое
BATCH_SIZE = config.get('batch_size', 10)

# --- Новый параметр: интервал между задачами (сек) ---
TASK_INTERVAL_SEC = config.get('task_interval_sec', 10)
MIN_TASK_INTERVAL_SEC = TASK_INTERVAL_SEC
MAX_TASK_INTERVAL_SEC = config.get('max_task_interval_sec', 120)
SUCCESS_DECREASE_STEP = config.get('success_decrease_step', 2)
SUCCESS_DECREASE_AFTER = config.get('success_decrease_after', 3)

# --- Динамическая пауза ---
current_pause = TASK_INTERVAL_SEC
success_counter = 0

LOGS_DIR.mkdir(parents=True, exist_ok=True)
PROCESSED_DIR.mkdir(parents=True, exist_ok=True)
# Настройка ротации логов: rotation='10 MB', retention=5, level=config.get('log_level', 'INFO')
logger.add(str(LOGS_DIR / 'processor.log'), rotation='10 MB', retention=5, level=config.get('log_level', 'INFO'))

# Пример схемы задачи
TASK_SCHEMA = {
    "type": "object",
    "properties": {
        "task_id": {"type": "string"},
        "product_data": {"type": "object"},
        "original_image": {"type": "string"},
        "template": {"type": "string"},
        "output_filename": {"type": "string"},
    },
    "required": ["task_id", "product_data", "original_image", "template", "output_filename"]
}

# --- КОНСТАНТЫ ДЛЯ КОМПОНОВКИ ---
COEFF = {
    'brand_font': 0.103,
    'brand_y': 0.058,
    'model_font': 0.052,
    'model_y': 0.156,
    'specs_main_font': 0.083,
    'specs_rim_font': 0.083,
    'specs_x': 0.5,
    'specs_y': 0.213,
    'specs_main_w': 0.5596,
    'specs_main_h': 0.0859,
	'main_text_x': 0.0505,
    'main_text_y': 0.279,
    'index_box_w': 0.1951,
    'index_box_h': 0.1295,
    'season_font': 0.0419,
    'season_x': 0.0639,
    'season_y': 0.7966,
    'season_y2': 0.8329,
    'tire_w': 0.6612,
    'tire_h': 0.6392,
    'tire_x': 0.3129,
    'tire_y': 0.3087,
    'icon_w': 0.0871,
    'icon_h': 0.0726,
    'icon_x': 0.2661,
    'icon_y': 0.79,
}

# --- Альтернативный режим восстановления шины под логотипом ---
ALT_TIRE_INPAINT = True  # Включить альтернативный inpaint только по шине

def validate_task_json(task):
    try:
        validate(instance=task, schema=TASK_SCHEMA)
        return True, None
    except ValidationError as e:
        return False, str(e)

# ---------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ----------
def get_font(size, font_path):
    """
    Получить объект ImageFont. Если font_path невалиден — fallback на системный шрифт или дефолтный.
    :param size: размер шрифта
    :param font_path: путь к ttf-файлу
    :return: ImageFont
    """
    from PIL import ImageFont
    import os
    import sys
    if font_path and os.path.exists(font_path):
        try:
            if args.debug:
                logger.debug(f"Используется кастомный шрифт: {font_path}")
            return ImageFont.truetype(font_path, int(size))
        except Exception as e:
            logger.warning(f"Ошибка загрузки кастомного шрифта '{font_path}': {e}. Пробую системный fallback.")
    try:
        if sys.platform.startswith('win'):
            if args.debug:
                logger.debug("Используется системный шрифт: arial.ttf (Windows)")
            return ImageFont.truetype("arial.ttf", int(size))
        else:
            if args.debug:
                logger.debug("Используется системный шрифт: DejaVuSans.ttf (Linux/Mac)")
            return ImageFont.truetype("DejaVuSans.ttf", int(size))
    except Exception as e:
        logger.warning(f"Ошибка загрузки системного шрифта: {e}. Используется ImageFont.load_default().")
        return ImageFont.load_default()

# ---- ФУНКЦИИ РИСОВКИ ПО МАКЕТУ ----
def draw_brand(draw, text, width, height, font_path_bold, WHITE, debug_logging=False):
    font_size = int(width * COEFF['brand_font'])
    font = get_font(font_size, font_path_bold)
    x = width // 2
    y = int(height * COEFF['brand_y'])
    bbox = font.getbbox(text) if hasattr(font, 'getbbox') else (0, 0, *font.getmask(text).size)
    block_w = bbox[2] - bbox[0]
    block_h = bbox[3] - bbox[1]
    if debug_logging:
        print(f'draw_brand: text="{text}", x={x}, y={y}, font_size={font_size}, block_w={block_w}, block_h={block_h}')
        logger.debug(f'draw_brand: text="{text}", x={x}, y={y}, font_size={font_size}, block_w={block_w}, block_h={block_h}')
    draw.text((x, y), text, font=font, fill=WHITE, anchor='mt')

def draw_model(draw, text, width, height, font_path_semibold, WHITE, debug_logging=False):
    font_size = int(width * COEFF['model_font'])
    font = get_font(font_size, font_path_semibold)
    x = width // 2
    y = int(height * COEFF['model_y'])
    bbox = font.getbbox(text) if hasattr(font, 'getbbox') else (0, 0, *font.getmask(text).size)
    block_w = bbox[2] - bbox[0]
    block_h = bbox[3] - bbox[1]
    if debug_logging:
        print(f'draw_model: text="{text}", x={x}, y={y}, font_size={font_size}, block_w={block_w}, block_h={block_h}')
        logger.debug(f'draw_model: text="{text}", x={x}, y={y}, font_size={font_size}, block_w={block_w}, block_h={block_h}')
    draw.text((x, y), text, font=font, fill=WHITE, anchor='mt')

def draw_specs(draw, main_text, rim_text, width, height, font_path_semibold, font_path_bold, BLACK, WHITE, debug_logging=False):
    main_font = get_font(int(width * COEFF['specs_main_font']), font_path_semibold)
    rim_font = get_font(int(width * COEFF['specs_rim_font']), font_path_bold)
    specs_x = int(width * COEFF['specs_x'])
    specs_y = int(height * COEFF['specs_y'])
    main_w = int(width * COEFF['specs_main_w'])
    main_h = int(height * COEFF['specs_main_h'])
    main_text_x = int(main_w * COEFF['main_text_x'])
    main_text_y = int(height * COEFF['main_text_y'])
    bbox_main = main_font.getbbox(main_text) if hasattr(main_font, 'getbbox') else (0, 0, *main_font.getmask(main_text).size)
    block_w_main = bbox_main[2] - bbox_main[0]
    block_h_main = bbox_main[3] - bbox_main[1]
    if debug_logging:
        print(f'draw_specs: main_text="{main_text}", x={(specs_x - main_w//2) + main_text_x}, y={specs_y + main_text_y}, font_size={main_font.size}, block_w={block_w_main}, block_h={block_h_main}')
        logger.debug(f'draw_specs: main_text="{main_text}", x={(specs_x - main_w//2) + main_text_x}, y={specs_y + main_text_y}, font_size={main_font.size}, block_w={block_w_main}, block_h={block_h_main}')
    bbox_rim = rim_font.getbbox(rim_text) if hasattr(rim_font, 'getbbox') else (0, 0, *rim_font.getmask(rim_text).size)
    block_w_rim = bbox_rim[2] - bbox_rim[0]
    block_h_rim = bbox_rim[3] - bbox_rim[1]
    if debug_logging:
        print(f'draw_specs: rim_text="{rim_text}", font_size={rim_font.size}, block_w={block_w_rim}, block_h={block_h_rim}')
        logger.debug(f'draw_specs: rim_text="{rim_text}", font_size={rim_font.size}, block_w={block_w_rim}, block_h={block_h_rim}')
    # main_text: левый край прямоугольника + отступ main_text_x
    draw.text(
        ((specs_x - main_w//2) + main_text_x, main_text_y),
        main_text, font=main_font, fill=BLACK, anchor='ls'
    )
    # rim_text: центр прямоугольника rim_rect по X, main_text_y по Y (как в оригинале)
    rim_space = int(main_h * 0.0698)
    rim_x = (specs_x - main_w//2) + int(main_w * 0.6379)
    rim_x2 = (specs_x + main_w//2) - rim_space
    rim_w = rim_x2 - rim_x
    draw.text(
        (rim_x + rim_w//2, main_text_y),
        rim_text, font=rim_font, fill=WHITE, anchor='ms'
    )

def draw_index_box(draw, value, width, height, x, y, font_path_bold, WHITE, debug_logging=False):
    box_w = int(width * COEFF['index_box_w'])
    box_h = int(height * COEFF['index_box_h'])
    num_font = get_font(int(width * 0.0629), font_path_bold)
    cx = x + int(box_w * 0.0682)
    cy = y + int(box_h * 0.0526)
    bbox_val = num_font.getbbox(value) if hasattr(num_font, 'getbbox') else (0, 0, *num_font.getmask(value).size)
    block_w_val = bbox_val[2] - bbox_val[0]
    block_h_val = bbox_val[3] - bbox_val[1]
    if debug_logging:
        print(f'draw_index_box: value="{value}", x={cx}, y={cy}, font_size={num_font.size}, block_w={block_w_val}, block_h={block_h_val}')
        logger.debug(f'draw_index_box: value="{value}", x={cx}, y={cy}, font_size={num_font.size}, block_w={block_w_val}, block_h={block_h_val}')
    draw.text((cx, cy), value, font=num_font, fill=WHITE, anchor='lt')

def draw_tire(img, tire_img, width, height):
    tire_w = int(width * COEFF['tire_w'])
    tire_h = int(height * COEFF['tire_h'])
    x = int(width * COEFF['tire_x'])
    y = int(height * COEFF['tire_y'])
    # --- Новая логика вписывания с сохранением пропорций ---
    orig_w, orig_h = tire_img.size
    rect_w, rect_h = tire_w, tire_h
    aspect_orig = orig_w / orig_h
    aspect_rect = rect_w / rect_h
    if aspect_orig < aspect_rect:
        # Изображение более "высокое" — вписываем по высоте
        new_h = rect_h
        new_w = int(orig_w * (rect_h / orig_h))
        offset_x = x + (rect_w - new_w) // 2
        offset_y = y
    else:
        # Изображение более "широкое" — вписываем по ширине
        new_w = rect_w
        new_h = int(orig_h * (rect_w / orig_w))
        offset_x = x
        offset_y = y + (rect_h - new_h)
    tire_resized = tire_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
    img.paste(tire_resized, (offset_x, offset_y), tire_resized)

# --- Вспомогательные функции ---
def crop_to_content(img):
    """
    Обрезает изображение по содержимому (убирает пустые края).
    Работает с RGBA изображениями, учитывая прозрачность.
    """
    arr = np.array(img)
    
    # Если изображение RGB, конвертируем в RGBA
    if arr.shape[2] == 3:
        arr = np.dstack([arr, np.full(arr.shape[:2], 255, dtype=np.uint8)])
    
    # Создаем маску непрозрачных пикселей
    if arr.shape[2] == 4:
        # Для RGBA: учитываем альфа-канал
        mask = arr[..., 3] > 10  # Небольшой порог для учета полупрозрачности
    else:
        # Для RGB: считаем все пиксели непрозрачными
        mask = np.ones(arr.shape[:2], dtype=bool)
    
    # Находим границы содержимого
    coords = np.argwhere(mask)
    if coords.size == 0:
        logger.warning("crop_to_content: не найдено содержимое для обрезки")
        return img
    
    y0, x0 = coords.min(axis=0)
    y1, x1 = coords.max(axis=0) + 1
    
    # Добавляем небольшой отступ
    h, w = arr.shape[:2]
    x0 = max(0, x0 - 5)
    y0 = max(0, y0 - 5)
    x1 = min(w, x1 + 5)
    y1 = min(h, y1 + 5)
    
    if args.debug:
        print(f'crop_to_content: crop box (x0={x0}, y0={y0}, x1={x1}, y1={y1})')
        logger.debug(f'crop_to_content: crop box (x0={x0}, y0={y0}, x1={x1}, y1={y1})')
    
    return img.crop((x0, y0, x1, y1))

def resolve_font_path(font_path):
    if not font_path:
        return None
    if font_path.startswith('uploads/'):
        project_root = Path(__file__).parent.parent.resolve()
        abs_path = project_root / font_path
        return str(abs_path)
    return font_path

# --- Универсальная функция удаления логотипа с автоадаптацией ---
def get_salient_mask_u2net(img):
    from u2net import U2NETPredictor
    import torch
    from pathlib import Path
    weights_path = (Path(__file__).parent / 'u2net.pth').resolve()
    predictor = U2NETPredictor(weights_path=weights_path)
    mask = predictor.predict(img.convert('RGB'))
    return Image.fromarray(mask).convert('L')

def get_auto_color_masks(img, roi_height_ratio=0.35, min_bright=180, color_quant=16, top_colors=3):
    """
    Автоматически находит самые яркие и часто встречающиеся цвета в нижней части изображения
    (без sklearn, только numpy!). Маски по цвету + яркость.
    color_quant — степень округления цветов (16: цвета округляются к ближайшему кратному 16)
    """
    img_np = np.array(img.convert('RGB'))
    h, w, _ = img_np.shape
    roi = img_np[int(h*(1-roi_height_ratio)):, :, :]

    # Маска по яркости (белый, светлый логотип)
    gray = cv2.cvtColor(roi, cv2.COLOR_RGB2GRAY)
    _, mask_bright = cv2.threshold(gray, min_bright, 255, cv2.THRESH_BINARY)

    # Грубое квантование цвета (убираем шум)
    roi_quant = (roi // color_quant) * color_quant
    roi_flat = roi_quant.reshape(-1, 3)
    # Получаем уникальные цвета и их частоты
    uniq, counts = np.unique(roi_flat, axis=0, return_counts=True)
    idxs = np.argsort(-counts)[:top_colors]
    dominant_colors = uniq[idxs]

    # Строим маски для каждого доминирующего цвета
    mask_colors = np.zeros_like(mask_bright)
    for color in dominant_colors:
        lower = np.clip(color - color_quant, 0, 255)
        upper = np.clip(color + color_quant, 0, 255)
        mask = cv2.inRange(roi, lower, upper)
        mask_colors = cv2.bitwise_or(mask_colors, mask)

    # Маска логотипа: яркие области + цветовые
    mask_logo = cv2.bitwise_or(mask_bright, mask_colors)
    # Маску возвращаем в размер всего изображения
    full_mask = np.zeros((h, w), dtype=np.uint8)
    full_mask[int(h*(1-roi_height_ratio)):, :] = mask_logo
    return full_mask

def remove_logo_opencv(img, mask_salient, mask_auto, debug_path_prefix=None):
    import cv2
    import numpy as np
    img_np = np.array(img)
    # Если mask_salient полностью нулевой, используем только mask_auto
    if np.count_nonzero(mask_salient) == 0:
        inpaint_mask = (mask_auto > 128).astype(np.uint8) * 255
        img_inpaint = cv2.inpaint(img_np[..., :3], inpaint_mask, 7, cv2.INPAINT_TELEA)
        img_result = np.dstack([img_inpaint, img_np[..., 3]])
        if debug_path_prefix:
            mask_img = Image.fromarray(inpaint_mask)
            if f'{debug_path_prefix}_mask.png'.lower().endswith(('.jpg', '.jpeg')) and mask_img.mode == 'RGBA':
                mask_img = mask_img.convert('RGB')
            mask_img.save(f'{debug_path_prefix}_mask.png')
            inpaint_img = Image.fromarray(img_result, mode='RGBA')
            if f'{debug_path_prefix}_inpaint.png'.lower().endswith(('.jpg', '.jpeg')) and inpaint_img.mode == 'RGBA':
                inpaint_img = inpaint_img.convert('RGB')
            inpaint_img.save(f'{debug_path_prefix}_inpaint.png')
        return Image.fromarray(img_result, mode='RGBA')
    # --- стандартная логика для остальных случаев ---
    if ALT_TIRE_INPAINT:
        # 1. Маска шины (salient)
        mask_tire = (mask_salient > 128)
        # 2. Маска логотипа (auto)
        mask_logo = (mask_auto > 128)
        # 3. Маска "шина без логотипа"
        mask_tire_wo_logo = mask_tire & (~mask_logo)
        # 4. Вырезать только видимую часть шины
        tire_visible = np.zeros_like(img_np)
        tire_visible[mask_tire_wo_logo] = img_np[mask_tire_wo_logo]
        # 5. Inpaint только по тем пикселям, где был логотип (внутри шины)
        inpaint_mask = (mask_tire & mask_logo).astype(np.uint8) * 255
        tire_inpainted = cv2.inpaint(tire_visible[..., :3], inpaint_mask, 7, cv2.INPAINT_TELEA)
        # 6. Собрать итоговую шину
        result = img_np.copy()
        result[mask_tire] = np.dstack([tire_inpainted, img_np[..., 3]])[mask_tire]
        if debug_path_prefix:
            mask_img = Image.fromarray(inpaint_mask)
            if f'{debug_path_prefix}_mask.png'.lower().endswith(('.jpg', '.jpeg')) and mask_img.mode == 'RGBA':
                mask_img = mask_img.convert('RGB')
            mask_img.save(f'{debug_path_prefix}_mask.png')
            inpaint_img = Image.fromarray(np.dstack([tire_inpainted, img_np[..., 3]]), mode='RGBA')
            if f'{debug_path_prefix}_inpaint.png'.lower().endswith(('.jpg', '.jpeg')) and inpaint_img.mode == 'RGBA':
                inpaint_img = inpaint_img.convert('RGB')
            inpaint_img.save(f'{debug_path_prefix}_inpaint.png')
        return Image.fromarray(result, mode='RGBA')
    else:
        # Находим все контуры на салентной маске (чтобы оставить только основной объект - шину)
        contours, _ = cv2.findContours((mask_salient > 128).astype(np.uint8), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if contours:
            main_idx = int(np.argmax([cv2.contourArea(c) for c in contours]))
            main_mask = np.zeros_like(mask_salient)
            cv2.drawContours(main_mask, [contours[main_idx]], -1, 255, -1)
            extra_mask = ((mask_salient > 128) & (main_mask == 0)).astype(np.uint8) * 255
        else:
            extra_mask = np.zeros_like(mask_salient)
        final_mask = cv2.bitwise_or(mask_auto, extra_mask)
        kernel = np.ones((9,9), np.uint8)
        final_mask = cv2.morphologyEx(final_mask, cv2.MORPH_CLOSE, kernel)
        final_mask = cv2.dilate(final_mask, kernel, iterations=1)
        img_inpaint = cv2.inpaint(img_np[..., :3], final_mask, 7, cv2.INPAINT_TELEA)
        img_result = np.dstack([img_inpaint, img_np[..., 3]])
        if debug_path_prefix:
            mask_img = Image.fromarray(final_mask)
            if f'{debug_path_prefix}_mask.png'.lower().endswith(('.jpg', '.jpeg')) and mask_img.mode == 'RGBA':
                mask_img = mask_img.convert('RGB')
            mask_img.save(f'{debug_path_prefix}_mask.png')
            inpaint_img = Image.fromarray(img_result, mode='RGBA')
            if f'{debug_path_prefix}_inpaint.png'.lower().endswith(('.jpg', '.jpeg')) and inpaint_img.mode == 'RGBA':
                inpaint_img = inpaint_img.convert('RGB')
            inpaint_img.save(f'{debug_path_prefix}_inpaint.png')
        return Image.fromarray(img_result, mode='RGBA')

def remove_logo_lama(img, mask_salient, mask_auto, debug_path_prefix=None):
    """
    Удаление логотипа с помощью lama-cleaner через HTTP API (серверный режим).
    """
    import numpy as np
    from PIL import Image
    import os
    import io
    import requests
    import traceback
    # Генерируем итоговую маску (аналогично OpenCV)
    final_mask = np.bitwise_or(mask_salient, mask_auto)
    # Логируем статистику маски
    nonzero = np.count_nonzero(final_mask)
    logger.debug(f'LAMA_MASK: shape={final_mask.shape}, nonzero={nonzero}')
    if nonzero == 0:
        logger.error('LAMA_MASK: Маска пуста, inpaint невозможен!')
        raise RuntimeError('Маска для lama-cleaner пуста, inpaint невозможен.')
    # Сохраняем input и mask во временные in-memory файлы
    img_bytes = io.BytesIO()
    img.save(img_bytes, format='PNG')
    img_bytes.seek(0)
    mask_img = Image.fromarray(final_mask)
    mask_bytes = io.BytesIO()
    mask_img.save(mask_bytes, format='PNG')
    mask_bytes.seek(0)
    # Сохраняем для отладки
    if debug_path_prefix:
        img.save(f'{debug_path_prefix}_lama_input.png')
        mask_img.save(f'{debug_path_prefix}_lama_mask.png')
    # Дамп multipart-запроса
    logger.debug(f'LAMA_API_DUMP: image size={img.size}, mask size={mask_img.size}, img_bytes={len(img_bytes.getvalue())}, mask_bytes={len(mask_bytes.getvalue())}')
    logger.debug(f'LAMA_API_DUMP: img_bytes[:16]={img_bytes.getvalue()[:16]}, mask_bytes[:16]={mask_bytes.getvalue()[:16]}')
    # Настройки lama-cleaner API
    lama_url = os.environ.get('LAMA_API_URL', 'http://127.0.0.1:8080/inpaint')
    files = {
        'image': ('input.png', img_bytes, 'image/png'),
        'mask': ('mask.png', mask_bytes, 'image/png'),
    }
    data = {
        'model': 'lama',
        'device': 'cpu',
        'prompt': '',
        'steps': 1,
        'sampler': 'ddim',
        'hd_strategy': 'Original',
        'hd_strategy_crop_margin': 32,
        'hd_strategy_crop_trigger_size': 1280,
        'hd_strategy_resize_limit': 2048,
        'use_croper': False,
        'croper_x': 0,
        'croper_y': 0,
        'croper_height': 0,
        'croper_width': 0,
        'return_mask': False,
        'return_origin': False,
    }
    try:
        response = requests.post(lama_url, files=files, data=data, timeout=120)
        response.raise_for_status()
        result_img = Image.open(io.BytesIO(response.content)).convert('RGBA')
        if debug_path_prefix:
            inpaint_img = result_img
            inpaint_img.save(f'{debug_path_prefix}_lama_inpaint.png')
        return result_img
    except Exception as e:
        tb = traceback.format_exc()
        logger.error(f'# LAMA_API_PATCH: Ошибка lama-cleaner API: {e}\n{tb}')
        raise RuntimeError(f'lama-cleaner API error: {e}\n{tb}')

def remove_logo_from_object(img, mask_path=None, logo_removal_method='runwayml', debug_path_prefix=None, params=None):
    if img.mode != 'RGBA':
        img = img.convert('RGBA')
    if logo_removal_method == 'runwayml':
        prompt = None
        if params and params.get('runwayml_prompt'):
            prompt = params.get('runwayml_prompt')
        else:
            prompt = 'Remove any object overlapping the main subject (if present), including logos and watermarks. After removal, realistically restore the main image. The main subject is a car tire on a wheel, standing vertically. Do not change, alter, distort, or remove any markings, symbols, texts, numbers, or labels present on the tire sidewalls, the tire itself, or the wheel. Improve the image quality. Output: a single car tire on a wheel, standing vertically.'
        api_key = str(params.get('runwayml_api_key')) if params and params.get('runwayml_api_key') is not None else None
        logger.info(f"Удаление логотипа и фона методом: runwayml, prompt={prompt}")
        logger.info(f"API-ключ из params: {'передан' if api_key else 'НЕ передан'}")
        if not api_key:
            logger.error("API-ключ RunwayML не передан!")
            logger.error("Проверьте: 1) переменную окружения RUNWAYML_API_KEY, 2) передачу ключа в params задачи")
            raise RuntimeError("API-ключ RunwayML не передан!")
        return remove_logo_runwayml(img, prompt, api_key, debug_path_prefix)
    # --- Для активации других методов раскомментируйте и доработайте код ниже ---
    # elif logo_removal_method == 'lama':
    #     ...
    # else:
    #     ...
    # ------------------------------------------------------

def remove_logo_runwayml(img, prompt, api_key, debug_path_prefix=None):
    """
    Удаление логотипа и восстановление объекта через runwayml.com API.
    Использует официальный SDK RunwayML.
    
    Изображение автоматически ресайзится до максимальной стороны 720px
    с сохранением пропорций. Соотношение сторон передается точно
    как f"{width}:{height}".
    
    Документация: https://docs.dev.runwayml.com/
    """
    import io
    import base64
    from PIL import Image
    
    try:
        from runwayml import RunwayML, TaskFailedError
    except ImportError:
        logger.error("[RunwayML] SDK RunwayML не установлен. Установите: pip install runwayml")
        raise RuntimeError("SDK RunwayML не установлен. Установите: pip install runwayml")
    
    api_key = str(api_key) if api_key is not None else ''
    masked_key = (api_key[:5] + '...' + str(len(api_key))) if api_key else '(none)'
    logger.info(f"[RunwayML] Используется API-ключ: {masked_key}")
    
    if not api_key:
        logger.error("API-ключ RunwayML не передан или пуст!")
        raise RuntimeError("API-ключ RunwayML не передан или пуст!")
    
    logger.info(f"[RunwayML] Старт отправки изображения на runwayml.com для удаления логотипа. Prompt: {prompt}")
    
    try:
        # Инициализируем клиент RunwayML
        client = RunwayML(api_key=api_key)
        
        # Ресайзим изображение для RunwayML с сохранением пропорций
        # Цель: получить изображение, которое поместится в квадрат 720x720
        w, h = img.size
        target_size = 720
        
        if w > h and w > target_size:
            # Ширина больше высоты и больше 720 - уменьшаем ширину до 720, высота в пропорции
            new_w = target_size
            new_h = int(h * target_size / w)
        elif w < h and h > target_size:
            # Высота больше ширины и больше 720 - уменьшаем высоту до 720, ширина в пропорции
            new_h = target_size
            new_w = int(w * target_size / h)
        elif w == h and w > target_size:
            # Квадратное изображение - обе стороны до 720, если они больше 720
            new_w = target_size
            new_h = target_size
        else:
            # Изображение уже меньше или равно 720px - оставляем как есть
            new_w = w
            new_h = h
        
        img_resized = img.resize((new_w, new_h), Image.Resampling.LANCZOS)
        logger.info(f"[RunwayML] Изображение ресайзено: {w}x{h} -> {new_w}x{new_h}")
        
        # Конвертируем ресайзенное изображение в base64 data URI
        img_bytes = io.BytesIO()
        img_resized.save(img_bytes, format='PNG')
        img_bytes.seek(0)
        base64_image = base64.b64encode(img_bytes.getvalue()).decode("utf-8")
        data_uri = f"data:image/png;base64,{base64_image}"
        
        # Используем фиксированное соотношение 720:720 для RunwayML
        ratio = "720:720"
        logger.info(f"[RunwayML] Используется соотношение: {ratio}")
        
        task = client.text_to_image.create(
            model='gen4_image',
            ratio=ratio,
            prompt_text=prompt,
            reference_images=[
                {
                    'uri': data_uri,
                    'tag': 'original',
                },
            ],
        ).wait_for_task_output()
        
        logger.info("[RunwayML] Задача выполнена успешно")
        
        # Получаем результат (URL изображения)
        if task.output and len(task.output) > 0:
            image_url = task.output[0]
            logger.info(f"[RunwayML] Получен URL результата: {image_url}")
            
            # Скачиваем изображение
            import requests
            response = requests.get(image_url, timeout=120)
            response.raise_for_status()
            
            # Конвертируем в PIL Image
            result_img = Image.open(io.BytesIO(response.content)).convert('RGBA')
            
            if debug_path_prefix:
                result_img.save(f'{debug_path_prefix}_runwayml_result.png')
            
            logger.info("[RunwayML] Успешно получено изображение без логотипа.")
            return result_img
        else:
            raise RuntimeError("RunwayML не вернул результат изображения")
            
    except TaskFailedError as e:
        logger.error(f"[RunwayML] Задача не выполнена: {e}")
        logger.error(f"[RunwayML] Детали ошибки: {e.task_details}")
        raise RuntimeError(f"RunwayML task failed: {e}")
    except Exception as e:
        logger.error(f"[RunwayML] Ошибка при обращении к runwayml.com: {e}")
        # Добавляем дополнительную диагностику согласно документации
        if hasattr(e, 'response') and hasattr(e.response, 'text'):
            logger.error(f"[RunwayML] Тело ответа: {e.response.text}")
        raise RuntimeError(f"RunwayML API error: {e}")

def mask_api_key(key: str) -> str:
    if not key or len(key) < 8:
        return '****'
    return '*' * (len(key) - 4) + key[-4:]

# --- настройка логирования с ротацией и отдельным errors.log ---
def setup_logging(debug_mode=False):
    from loguru import logger as loguru_logger
    logger.remove()
    log_level = 'DEBUG' if debug_mode else 'INFO'
    loguru_logger.add(str(LOGS_DIR / 'processor.log'), rotation='1 week', retention='4 weeks', level=log_level)
    loguru_logger.add(str(LOGS_DIR / 'errors.log'), rotation='1 week', retention='4 weeks', level='ERROR')
    return loguru_logger

# --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---
# Добавляем функцию для удалённого rembg

def remove_bg_rembg_remote(img: Image.Image, rembg_url: str, username: str = None, password: str = None) -> Image.Image:
    import io
    import requests
    from PIL import Image
    import logging
    if img is None:
        logger.error('[rembg] Передано пустое изображение (None)')
        raise ValueError('Передано пустое изображение (None)')
    if not isinstance(img, Image.Image):
        logger.error(f'[rembg] Передан не объект PIL.Image.Image, а {type(img)}')
        raise TypeError(f'Ожидался PIL.Image.Image, а получено: {type(img)}')
    buf = io.BytesIO()
    img.save(buf, format='PNG')
    buf.seek(0)
    logger.info(f'[rembg] Размер изображения для отправки: {buf.getbuffer().nbytes} байт, mode={img.mode}, size={img.size}')
    # Временно сохраняем для отладки
    try:
        img.save('debug_rembg_input.png')
    except Exception as e:
        logger.warning(f'[rembg] Не удалось сохранить debug_rembg_input.png: {e}')
    auth = (username, password) if username and password else None
    try:
        response = requests.post(
            f"{rembg_url}/api/remove",
            files={'file': ('image.png', buf, 'image/png')},
            auth=auth,
            timeout=120
        )
        logger.info(f'[rembg] Ответ rembg: {response.status_code}, длина: {len(response.content)} байт')
        response.raise_for_status()
        result_img = Image.open(io.BytesIO(response.content)).convert('RGBA')
        # Временно сохраняем результат для отладки
        try:
            result_img.save('debug_rembg_output.png')
        except Exception as e:
            logger.warning(f'[rembg] Не удалось сохранить debug_rembg_output.png: {e}')
        return result_img
    except Exception as e:
        logger.error(f'[rembg] Ошибка при обращении к rembg: {e}')
        raise

# --- Основная функция обработки ---
def process_image(task):
    """
    Основная функция обработки задачи: валидирует, проверяет файлы, компилирует изображение, логирует этапы.
    Новый порядок: 1) удаление логотипа, 2) удаление фона (rembg), 3) crop, 4) композит.
    Внедрён суперсэмплинг и постобработка для повышения качества итогового изображения.
    """
    try:
        params = task.get('params', {})
        def get_param(key, default=None):
            return params.get(key) or config.get(key) or default
        debug_logging = params.get('debug_logging', False)
        width = int(get_param('width', 620))
        height = int(get_param('height', 826))
        logo_removal_method = get_param('logo_removal_method', 'opencv')
        logger.info(f"[PROCESS] Запуск обработки изображения. Метод удаления логотипа: {logo_removal_method}")
        # Пути к файлам
        orig_path = ORIGINALS_DIR / Path(str(task['original_image'])).name
        background_path = TEMPLATES_DIR / Path(str(task['template'])).name
        output_path = PROCESSED_DIR / Path(str(task['output_filename'])).name
        FONT_PATH_BOLD = resolve_font_path(get_param('font_bold', 'Inter-Bold.ttf'))
        FONT_PATH_SEMIBOLD = resolve_font_path(get_param('font_semibold', 'Inter-SemiBold.ttf'))
        FONT_PATH_REGULAR = resolve_font_path(get_param('font_regular', 'Inter-Regular.ttf'))
        # Проверка существования файлов
        for p, label in [
            (orig_path, 'оригинал'),
            (background_path, 'шаблон'),
            (FONT_PATH_BOLD, 'шрифт bold'),
            (FONT_PATH_SEMIBOLD, 'шрифт semibold'),
            (FONT_PATH_REGULAR, 'шрифт regular')
        ]:
            if p and not os.path.exists(p):
                logger.error(f"Файл {label} не найден: {p}")
                return None, None
        # Цвета
        WHITE = get_param('color_white', '#FFFFFF')
        BLACK = get_param('color_black', '#222222')
        # Данные товара
        pd = task['product_data']
        BRAND = str(pd.get('brand', ''))
        MODEL = str(pd.get('model', ''))
        WIDTH_PROFILE = str(pd.get('width', ''))
        HEIGHT_PROFILE = str(pd.get('height', ''))
        RIM = str(pd.get('diameter', ''))
        LOAD_IDX = str(pd.get('load_index', ''))
        SPEED_IDX = str(pd.get('speed_index', ''))
        sku = pd.get('sku')
        norm_sku = pd.get('norm_sku') if 'norm_sku' in pd else None
        if not sku:
            logger.error('[PROCESS] В задаче отсутствует sku, обработка невозможна!')
            return None, None
        if not norm_sku:
            # Если norm_sku не передан, нормализуем sku вручную
            norm_sku = ''.join(c.lower() if c.isalnum() or c in ['-', '_'] else '' for c in sku)

        # === SUPER SAMPLING/POSTPROCESSING ===
        SUPER_SAMPLING_FACTOR = 3  # Можно увеличить до 3 для очень высоких требований
        width_ss = width * SUPER_SAMPLING_FACTOR
        height_ss = height * SUPER_SAMPLING_FACTOR
        # === SUPER SAMPLING/POSTPROCESSING ===

        # 1. Загружаем и подгоняем фон по размеру (Суперсэмплинг)
        background = Image.open(background_path).convert('RGBA').resize((width_ss, height_ss), Image.Resampling.LANCZOS)  # === SUPER SAMPLING/POSTPROCESSING ===
        img = background.copy()
        draw = ImageDraw.Draw(img)

        # 2. Открываем оригинал
        with Image.open(orig_path) as orig_img:
            orig_img = orig_img.convert('RGBA')
            logger.info("[PROCESS] Удаление логотипа...")
            tire_img = remove_logo_from_object(
                orig_img,
                task.get('logo_mask'),
                logo_removal_method=logo_removal_method,
                debug_path_prefix=(str(output_path).replace('.', '_debug1') if debug_logging else None),
                params=params
            )
            # --- Сохраняем gallery-версию runwayml-изображения (до rembg/crop) ---
            gallery_filename = f'product_{norm_sku}_ai-gallery.jpg'
            gallery_output_path = PROCESSED_DIR / gallery_filename
            if gallery_output_path.exists():
                logger.info(f'[SAVE] Gallery-версия runwayml уже существует: {gallery_output_path}')
            else:
                tire_img_to_save = tire_img
                # Сохраняем gallery-версию только в JPG
                if tire_img_to_save.mode in ('RGBA', 'LA'):
                    background = Image.new('RGB', tire_img_to_save.size, (255, 255, 255))
                    background.paste(tire_img_to_save, mask=tire_img_to_save.split()[-1])
                    tire_img_to_save = background
                else:
                    tire_img_to_save = tire_img_to_save.convert('RGB')
                gallery_output_path.parent.mkdir(parents=True, exist_ok=True)
                tire_img_to_save.save(gallery_output_path, format='JPEG', quality=100, subsampling=0)
                logger.info(f'[SAVE] Gallery-версия runwayml сохранена: {gallery_output_path}')
            if debug_logging:
                debug_path = str(gallery_output_path).replace('.', '_debug_runwayml.')
                img_to_save = tire_img
                if debug_path.lower().endswith((".jpg", ".jpeg")) and tire_img.mode == 'RGBA':
                    img_to_save = tire_img.convert('RGB')
                img_to_save.save(debug_path)
            if debug_logging:
                debug_path = str(output_path).replace('.', '_debug2_nologo.')
                img_to_save = tire_img
                if debug_path.lower().endswith((".jpg", ".jpeg")) and tire_img.mode == 'RGBA':
                    img_to_save = tire_img.convert('RGB')
                img_to_save.save(debug_path)
            # --- Новый вызов удалённого rembg ---
            rembg_url = get_param('rembg_url')
            rembg_user = get_param('rembg_user')
            rembg_pass = get_param('rembg_pass')
            if rembg_url:
                logger.info(f"[PROCESS] Удаление фона через удалённый rembg: {rembg_url}")
                try:
                    tire_img_nobg = remove_bg_rembg_remote(tire_img, rembg_url, rembg_user, rembg_pass)
                except Exception as e:
                    logger.error(f"Ошибка при удалении фона через rembg: {e}")
                    return None, None
                if tire_img_nobg.mode != 'RGBA':
                    tire_img_nobg = tire_img_nobg.convert('RGBA')
                if debug_logging:
                    debug_path = str(output_path).replace('.', '_debug3_nobg.')
                    img_to_save = tire_img_nobg
                    if debug_path.lower().endswith((".jpg", ".jpeg")) and tire_img_nobg.mode == 'RGBA':
                        img_to_save = tire_img_nobg.convert('RGB')
                    img_to_save.save(debug_path)
            else:
                logger.error("rembg_url не задан в config.yaml или params задачи!")
                return None, None
            tire_img_crop = crop_to_content(tire_img_nobg)
            if debug_logging:
                debug_path = str(output_path).replace('.', '_debug4_crop.')
                img_to_save = tire_img_crop
                if debug_path.lower().endswith((".jpg", ".jpeg")) and tire_img_crop.mode == 'RGBA':
                    img_to_save = tire_img_crop.convert('RGB')
                img_to_save.save(debug_path)

        # 6. Отрисовываем все элементы (Суперсэмплинг: подаем увеличенные размеры и шрифты)
        # draw_tire(img, tire_img_crop, width, height)
        draw_tire(img, tire_img_crop, width_ss, height_ss)  # === SUPER SAMPLING/POSTPROCESSING ===

        # draw_brand(draw, BRAND, width, height, FONT_PATH_BOLD, WHITE, debug_logging)
        draw_brand(draw, BRAND, width_ss, height_ss, FONT_PATH_BOLD, WHITE, debug_logging)  # === SUPER SAMPLING/POSTPROCESSING ===

        # draw_model(draw, MODEL, width, height, FONT_PATH_SEMIBOLD, WHITE, debug_logging)
        draw_model(draw, MODEL, width_ss, height_ss, FONT_PATH_SEMIBOLD, WHITE, debug_logging)  # === SUPER SAMPLING/POSTPROCESSING ===

        # draw_specs(draw, f"{WIDTH_PROFILE}/{HEIGHT_PROFILE}", RIM, width, height, FONT_PATH_SEMIBOLD, FONT_PATH_BOLD, BLACK, CYAN, LIGHT_BG, WHITE, debug_logging)
        draw_specs(draw, f"{WIDTH_PROFILE}/{HEIGHT_PROFILE}", RIM, width_ss, height_ss, FONT_PATH_SEMIBOLD, FONT_PATH_BOLD, BLACK, WHITE, debug_logging)  # === SUPER SAMPLING/POSTPROCESSING ===

        # draw_index_box(draw, LOAD_IDX, 'индекс', 'нагрузки', width, height, LOAD_IDX_BG, int(width*COEFF['season_x']), int(height*0.4521), FONT_PATH_BOLD, FONT_PATH_REGULAR, WHITE, debug_logging)
        draw_index_box(draw, LOAD_IDX, width_ss, height_ss, int(width_ss*COEFF['season_x']), int(height_ss*0.4521), FONT_PATH_BOLD, WHITE, debug_logging)  # === SUPER SAMPLING/POSTPROCESSING ===

        # draw_index_box(draw, SPEED_IDX, 'индекс', 'скорости', width, height, SPEED_IDX_BG, int(width*COEFF['season_x']), int(height*0.628), FONT_PATH_BOLD, FONT_PATH_REGULAR, WHITE, debug_logging)
        draw_index_box(draw, SPEED_IDX, width_ss, height_ss, int(width_ss*COEFF['season_x']), int(height_ss*0.628), FONT_PATH_BOLD, WHITE, debug_logging)  # === SUPER SAMPLING/POSTPROCESSING ===

        # --- SUPER SAMPLING: Уменьшаем изображение до целевого размера ---
        # Сначала применим постобработку для повышения резкости и коррекции цвета (до ресайза)
        from PIL import ImageEnhance, ImageFilter  # === SUPER SAMPLING/POSTPROCESSING ===

        # --- Отрисовка иконки сезонности (если задана) ---
        icon_path = get_param('icon_path')
        logger.info(f'[ICON-DEBUG] icon_path из params: {icon_path}')
        if icon_path:
            icon_full_path = (PROJECT_ROOT / 'uploads' / 'ai_image' / icon_path).resolve()
            logger.info(f'[ICON-DEBUG] Полный путь к иконке: {icon_full_path}')
            if icon_full_path.exists():
                try:
                    icon_img = Image.open(icon_full_path).convert('RGBA')
                    # Размеры и позиция по COEFF (с учётом суперсэмплинга)
                    icon_w = int(width_ss * COEFF['icon_w'])
                    icon_h = int(height_ss * COEFF['icon_h'])
                    icon_x = int(width_ss * COEFF['icon_x'])
                    icon_y = int(height_ss * COEFF['icon_y'])
                    icon_img = icon_img.resize((icon_w, icon_h), Image.Resampling.LANCZOS)
                    img.alpha_composite(icon_img, (icon_x, icon_y))
                    logger.info(f'[ICON] Иконка сезонности отрисована: {icon_full_path}')
                    # Если требуется сохранить иконку отдельно для отладки — сохраняем с quality=100
                    if debug_logging:
                        debug_icon_path = str(output_path).replace('.', '_debug_icon.')
                        icon_img_to_save = icon_img
                        if debug_icon_path.lower().endswith((".jpg", ".jpeg")) and icon_img.mode == 'RGBA':
                            icon_img_to_save = icon_img.convert('RGB')
                        icon_img_to_save.save(debug_icon_path, quality=100)
                except Exception as e:
                    logger.error(f'[ICON] Ошибка отрисовки иконки: {e}')
            else:
                logger.warning(f'[ICON] Файл иконки не найден: {icon_full_path}')
        else:
            logger.warning('[ICON] icon_path не передан в параметры задачи!')

        # Постобработка: Повышение резкости
        enhancer_sharpness = ImageEnhance.Sharpness(img)
        img = enhancer_sharpness.enhance(2)  # 1.0 - без изменений, >1 - сильнее

        # Постобработка: Коррекция цвета (насыщенность)
        enhancer_color = ImageEnhance.Color(img)
        img = enhancer_color.enhance(1.3)  # 1.0 - без изменений, >1 - сильнее

        # Можно добавить лёгкую фильтрацию для очистки артефактов
        img = img.filter(ImageFilter.SMOOTH_MORE)  # по желанию

        img = img.resize((width, height), Image.Resampling.LANCZOS)  # === SUPER SAMPLING/POSTPROCESSING ===

        # --- Сохранение результата ---
        output_filename = str(task['output_filename'])
        # Принудительно меняем расширение на .jpg
        output_filename = Path(output_filename).with_suffix('.jpg').name
        if '/' in output_filename or '\\' in output_filename:
            final_output_path = PROCESSED_DIR / Path(output_filename).name
        else:
            final_output_path = PROCESSED_DIR / output_filename
        # Сохраняем основное изображение только в JPG
        img_to_save = img
        if img_to_save.mode in ('RGBA', 'LA'):
            background = Image.new('RGB', img_to_save.size, (255, 255, 255))
            background.paste(img_to_save, mask=img_to_save.split()[-1])
            img_to_save = background
        else:
            img_to_save = img_to_save.convert('RGB')
        final_output_path.parent.mkdir(parents=True, exist_ok=True)
        img_to_save.save(final_output_path, format='JPEG', quality=100, subsampling=0)
        logger.info(f'[SAVE] Файл сохранен: {final_output_path}')
        logger.info(f'[SAVE] Размер файла: {final_output_path.stat().st_size if final_output_path.exists() else "файл не найден"}')
        logger.info(f'Результат задачи {task["task_id"]} сохранён: {final_output_path}')

        # --- Сохраняем gallery-версию runwayml-изображения ---
        # gallery_filename = f'product_{sku}_ai-gallery.png'
        # gallery_output_path = PROCESSED_DIR / gallery_filename
        # img_to_save.save(gallery_output_path, quality=100, subsampling=0)
        # logger.info(f'[SAVE] Gallery-версия сохранена: {gallery_output_path}')

        # --- Формируем относительный путь для output_image ---
        rel_output_image = str(final_output_path.relative_to(PROJECT_ROOT / "uploads" / "ai_image"))
        rel_gallery_image = str(gallery_output_path.relative_to(PROJECT_ROOT / "uploads" / "ai_image"))

        return rel_output_image, rel_gallery_image
    except Exception as e:
        logger.error(f"[PROCESS] Ошибка обработки изображения: {e}")
        return None, None

def notify_wp_processing(task_id, config):
    url = config.get('wp_api_url')
    secret = config.get('wp_api_secret')
    if not url or not secret:
        logger.warning('WP API url/secret не заданы в config.yaml')
        return
    try:
        resp = requests.post(url, json={'task_id': task_id, 'secret': secret}, timeout=5)
        if resp.status_code == 200:
            logger.info(f'[WP] Статус processing успешно отправлен для {task_id}')
        else:
            logger.warning(f'[WP] Не удалось обновить статус для {task_id}: {resp.status_code} {resp.text}')
    except Exception as e:
        logger.error(f'[WP] Ошибка при отправке статуса processing: {e}')

def process_task(task_path):
    try:
        with open(task_path, 'r', encoding='utf-8') as f:
            task = json.load(f)
        ok, err = validate_task_json(task)
        task_id = task.get('task_id', task_path.stem)
        # --- Уведомление WordPress о начале обработки ---
        notify_wp_processing(task_id, config)
        if not ok:
            logger.error(f'Ошибка валидации задачи {task_id}: {err}')
            # --- Удаляем файл задачи после ошибки ---
            if os.path.exists(task_path):
                os.remove(task_path)
            return
        logger.info(f'Обработка задачи {task_id}')
        error_msg = None
        output_image = None
        gallery_image = None
        try:
            output_image, gallery_image = process_image(task)
        except Exception as e:
            import traceback
            error_msg = str(e) + '\n' + traceback.format_exc()
            logger.error(f'Ошибка обработки изображения: {error_msg}')
        status = 'success' if output_image else 'error'
        result = {
            'task_id': task_id,
            'product_id': task.get('product_id'),
            'status': status,
            'output_image': output_image,
            'gallery_image': gallery_image,
            'message': 'OK' if output_image else (error_msg or 'Ошибка обработки'),
            'started_at': '',
            'finished_at': '',
            'error': error_msg
        }
        result_path = RESULTS_DIR / f'{task_id}.json'
        with open(result_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
        # --- Удаляем файл задачи после обработки ---
        if os.path.exists(task_path):
            os.remove(task_path)
    except Exception as e:
        logger.error(f'Ошибка при обработке {task_path}: {e}')

def main():
    global current_pause, success_counter
    task_files = list(TASKS_DIR.glob('*.json'))[:BATCH_SIZE]
    for idx, task_file in enumerate(task_files):
        # 1. Проверяем статус processing
        try:
            with open(task_file, 'r', encoding='utf-8') as f:
                task = json.load(f)
        except Exception as e:
            logger.error(f"[LOCK] Ошибка чтения задачи {task_file}: {e}")
            continue
        if task.get('processing'):
            continue  # Уже в обработке
        # 2. Ставим статус processing (атомарно)
        task['processing'] = True
        try:
            with open(task_file, 'w', encoding='utf-8') as f:
                json.dump(task, f, ensure_ascii=False, indent=2)
        except Exception as e:
            logger.error(f"[LOCK] Ошибка записи processing в задачу {task_file}: {e}")
            continue
        # 3. Обрабатываем задачу с динамической паузой
        try:
            process_task(task_file)
            success_counter += 1
            if success_counter >= SUCCESS_DECREASE_AFTER:
                if current_pause > MIN_TASK_INTERVAL_SEC:
                    old_pause = current_pause
                    current_pause = max(MIN_TASK_INTERVAL_SEC, current_pause - SUCCESS_DECREASE_STEP)
                    logger.info(f"[DYN-PAUSE] Несколько успешных задач подряд, уменьшаем паузу: {old_pause} -> {current_pause} сек")
                success_counter = 0
        except Exception as e:
            import runwayml
            # Если ошибка связана с лимитом или соединением — увеличиваем паузу
            if isinstance(e, (runwayml.RateLimitError, runwayml.APIConnectionError)):
                old_pause = current_pause
                current_pause = min(MAX_TASK_INTERVAL_SEC, current_pause * 2)
                logger.warning(f"[DYN-PAUSE] Получен RateLimit/APIConnectionError, увеличиваем паузу: {old_pause} -> {current_pause} сек")
                success_counter = 0
            else:
                logger.error(f"[DYN-PAUSE] Ошибка обработки задачи: {e}")
        # --- Пауза между задачами, кроме последней ---
        if idx < len(task_files) - 1:
            logger.info(f"[PAUSE] Пауза {current_pause} сек перед следующей задачей...")
            time.sleep(current_pause)

if __name__ == '__main__':
    debug = args.debug
    logger = setup_logging(debug)
    main()
