import os
import json
import shutil
from pathlib import Path
import subprocess
import time
import glob
import sys

# Пути
BASE = Path(__file__).parent
CONFIG = BASE / 'config.yaml'
with open(CONFIG, 'r', encoding='utf-8') as f:
    config = json.loads(json.dumps(__import__('yaml').safe_load(f)))
# Корень проекта
PROJECT_ROOT = BASE.parent.resolve()
# Все директории теперь строятся от корня проекта
TASKS_DIR = (PROJECT_ROOT / config['tasks_dir']).resolve()
RESULTS_DIR = (PROJECT_ROOT / config['results_dir']).resolve()
ORIGINALS_DIR = (PROJECT_ROOT / config['originals_dir']).resolve()
PROCESSED_DIR = (PROJECT_ROOT / config['processed_dir']).resolve()
TEMPLATES_DIR = (PROJECT_ROOT / config['templates_dir']).resolve()
LOGOS_DIR = (PROJECT_ROOT / config['logos_dir']).resolve()

# 1. Подготовка тестовой задачи
TEST_TASK_ID = 'test_integration_001'
TEST_TASK = {
    "task_id": TEST_TASK_ID,
    "product_data": {
        "brand": "TestBrand",
        "model": "TestModel",
        "width": "205",
        "height": "55",
        "diameter": "R16"
    },
    "original_image": "originals/test_image.png",
    "template": "templates/test_template.png",
    "icon": "logos/test_icon.png",
    "output_filename": f"{TEST_TASK_ID}_ai.png"
}

# 2. Копируем тестовые изображения (заглушки)
def prepare_test_files():
    # Для test_image и test_template ищем файл с любым расширением
    for subdir, base, target_dir in [
        ('originals', 'test_image', ORIGINALS_DIR),
        ('templates', 'test_template', TEMPLATES_DIR)
    ]:
        found = False
        for ext in ['.png', '.jpg', '.jpeg']:
            src = BASE / 'test_assets' / f'{base}{ext}'
            if src.exists():
                dst = target_dir / f'{base}{ext}'
                print(f"Копирую из {src} в {dst}")
                dst.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(src, dst)
                found = True
                break
        if not found:
            raise FileNotFoundError(f'Не найден файл {base} с расширением .png/.jpg/.jpeg в test_assets')

def cleanup():
    for d, fname in [
        (TASKS_DIR, f'{TEST_TASK_ID}.json'),
        (RESULTS_DIR, f'{TEST_TASK_ID}.json'),
        (PROCESSED_DIR, f'{TEST_TASK_ID}_ai.png')
    ]:
        f = d / fname
        if f.exists():
            f.unlink()

def run_logo_removal_test(method):
    print(f'--- Тест удаления логотипа методом: {method} ---')
    cleanup()
    prepare_test_files()
    test_task = {
        "task_id": f"{TEST_TASK_ID}_{method}",
        "type": "tyre",
        "original_image": f"originals/test_image.jpg",
        "template": f"templates/test_template.jpg",
        "icon": f"logos/test_icon.png",
        "product_data": {
            "brand": "TestBrand",
            "model": "TestModel",
            "width": "205",
            "height": "55",
            "diameter": "R16",
            "load_index": "94",
            "speed_index": "T",
            "season": "зима",
            "studded": True
        },
        "output_filename": f"processed/{TEST_TASK_ID}_{method}_ai.jpg",
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%S+03:00"),
        "params": {
            "logo_removal_method": method,
            "debug_logging": True
        }
    }
    with open(TASKS_DIR / f'{TEST_TASK_ID}_{method}.json', 'w', encoding='utf-8') as f:
        json.dump(test_task, f, ensure_ascii=False, indent=2)
    print('Запуск подпроцесса:', [sys.executable, str(BASE / 'ai_image_processor.py'), '--task', str(TASKS_DIR / f'{TEST_TASK_ID}_{method}.json'), '--config', str(BASE / 'config.yaml'), '--debug'])
    result = subprocess.run([
        sys.executable, str(BASE / 'ai_image_processor.py'),
        '--task', str(TASKS_DIR / f'{TEST_TASK_ID}_{method}.json'),
        '--config', str(BASE / 'config.yaml'),
        '--debug'
    ], capture_output=True, text=True)
    print(result.stdout)
    if result.stderr:
        print('--- STDERR ---')
        print(result.stderr)
    result_file = RESULTS_DIR / f'{TEST_TASK_ID}_{method}.json'
    if result_file.exists():
        with open(result_file, 'r', encoding='utf-8') as f:
            result = json.load(f)
        print('Результат:', result)
        assert result['status'] == 'success', f'Статус задачи не success для метода {method}!'
        # Гибкая проверка: ищем файл по имени в папке processed
        out_img_name = result['output_image'].replace('\\', '/').split('/')[-1]
        found = False
        for f in PROCESSED_DIR.glob(f'*{out_img_name}*'):
            if f.exists():
                found = True
                break
        assert found, f'Финальное изображение не найдено для метода {method}!'
        print(f'Тест удаления логотипа методом {method} пройден успешно.')
    else:
        print('Результат не найден!')
        assert False, f'Результат не найден для метода {method}!'

def test_season_icon():
    print('--- Интеграционный тест: иконка сезонности ---')
    prepare_test_files()  # копирует test_image, test_template, test_icon
    test_task = {
        "task_id": "test_season_icon_001",
        "type": "tyre",
        "original_image": "originals/test_image.jpg",
        "template": "templates/test_template.jpg",
        "icon": "logos/test_icon.png",
        "product_data": {
            "brand": "TestBrand",
            "model": "TestModel",
            "width": "205",
            "height": "55",
            "diameter": "R16",
            "load_index": "94",
            "speed_index": "T",
            "season": "зимняя"
        },
        "output_filename": "processed/test_season_icon_001_ai.jpg",
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%S+03:00"),
        "params": {
            "font_bold": "uploads/ai_image/fonts/Inter-Bold.ttf",
            "font_semibold": "uploads/ai_image/fonts/Inter-SemiBold.ttf",
            "font_regular": "uploads/ai_image/fonts/Inter-Regular.ttf",
            "color_white": "#FFFFFF",
            "color_black": "#222222",
            "color_cyan": "#349FCD",
            "color_light_bg": "#FFFFFF",
            "color_load_idx_bg": "#349FCD",
            "color_speed_idx_bg": "#349FCD",
            "width": 620,
            "height": 826,
            "logo_removal_method": "lama"
        }
    }
    with open(TASKS_DIR / 'test_season_icon_001.json', 'w', encoding='utf-8') as f:
        json.dump(test_task, f, ensure_ascii=False, indent=2)
    print('Запуск подпроцесса:', [sys.executable, str(BASE / 'ai_image_processor.py'), '--task', str(TASKS_DIR / 'test_season_icon_001.json'), '--config', str(BASE / 'config.yaml'), '--debug'])
    result = subprocess.run([
        sys.executable, str(BASE / 'ai_image_processor.py'),
        '--task', str(TASKS_DIR / 'test_season_icon_001.json'),
        '--config', str(BASE / 'config.yaml'),
        '--debug'
    ], capture_output=True, text=True)
    print(result.stdout)
    if result.stderr:
        print('--- STDERR ---')
        print(result.stderr)
    result_file = RESULTS_DIR / 'test_season_icon_001.json'
    assert result_file.exists(), 'Результат не найден!'
    with open(result_file, 'r', encoding='utf-8') as f:
        result = json.load(f)
    assert result['status'] == 'success', 'Статус задачи не success!'
    out_img = PROCESSED_DIR / 'test_season_icon_001_ai.jpg'
    assert out_img.exists(), 'Финальное изображение не найдено!'
    print('Тест с иконкой сезонности успешно пройден! Проверьте визуально наличие иконки на итоговом изображении.')

def main():
    print('--- Интеграционный тест удаления логотипа: только YOLOv8 ---')
    run_logo_removal_test('yolov8')
    # test_season_icon()  # временно не запускаем
    cleanup()

if __name__ == '__main__':
    main() 