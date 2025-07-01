import os
import json
import shutil
from pathlib import Path
import subprocess
import time
import glob
import sys

# --- Загрузка переменных окружения из .env (python-dotenv) ---
try:
    from dotenv import load_dotenv
    env_path = Path(__file__).parent / '.env'
    print(f'[TEST] Ищем .env файл: {env_path}')
    if env_path.exists():
        print(f'[TEST] .env файл найден, загружаем переменные...')
        load_dotenv(dotenv_path=env_path)
        print(f'[TEST] RUNWAYML_API_KEY после загрузки: {"установлен" if os.environ.get("RUNWAYML_API_KEY") else "НЕ установлен"}')
    else:
        print(f'[TEST] .env файл НЕ найден: {env_path}')
except ImportError:
    print('[TEST] python-dotenv не установлен, пропускаем загрузку .env')
    pass  # если python-dotenv не установлен, просто пропускаем

# Пути
BASE = Path(__file__).parent
CONFIG = BASE / 'config.yaml'
with open(CONFIG, 'r', encoding='utf-8') as f:
    config = json.loads(json.dumps(__import__('yaml').safe_load(f)))
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
    # Удаляем все файлы задач с префиксом test_integration_001_
    for task_file in TASKS_DIR.glob(f'{TEST_TASK_ID}_*.json'):
        if task_file.exists():
            print(f'[TEST] Удаляю старую задачу: {task_file.name}')
            task_file.unlink()
    
    # Удаляем все файлы результатов с префиксом test_integration_001_
    for result_file in RESULTS_DIR.glob(f'{TEST_TASK_ID}_*.json'):
        if result_file.exists():
            print(f'[TEST] Удаляю старый результат: {result_file.name}')
            result_file.unlink()
    
    # Удаляем все файлы изображений с префиксом test_integration_001_
    for img_file in PROCESSED_DIR.glob(f'{TEST_TASK_ID}_*_ai.*'):
        if img_file.exists():
            print(f'[TEST] Удаляю старое изображение: {img_file.name}')
            img_file.unlink()

def run_logo_removal_test(method):
    print(f'--- Тест удаления логотипа методом: {method} ---')
    cleanup()
    prepare_test_files()
    
    # Логируем ключ runwayml для отладки
    runwayml_key = os.environ.get("RUNWAYML_API_KEY")
    if method == 'runwayml':
        if runwayml_key:
            print(f'[TEST] Передаём в задачу runwayml_api_key: {mask_key(runwayml_key)}')
        else:
            print('[TEST][ERROR] runwayml_api_key будет None в задаче!')
    
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
            "debug_logging": True,
            "runwayml_api_key": runwayml_key
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
        # Проверяем наличие финального изображения
        output_image = result.get('output_image')
        if not output_image:
            assert False, f'Путь к финальному изображению не указан для метода {method}!'
        
        # Извлекаем имя файла из пути
        out_img_name = output_image.replace('\\', '/').split('/')[-1]
        img_path = PROCESSED_DIR / out_img_name
        
        if not img_path.exists():
            print(f'[TEST][ERROR] Финальное изображение не найдено: {img_path}')
            print(f'[TEST][DEBUG] Содержимое папки processed:')
            for f in PROCESSED_DIR.iterdir():
                print(f'  {f.name}')
            assert False, f'Финальное изображение не найдено для метода {method}: {img_path}'
        
        print(f'[TEST] Финальное изображение найдено: {img_path}')
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

def mask_key(key):
    if not key or len(key) < 8:
        return '***'
    return key[:4] + '*' * (len(key)-6) + key[-2:]

def check_runwayml_key():
    key = os.environ.get('RUNWAYML_API_KEY')
    if not key:
        print('[TEST][ERROR] Не найден ключ RUNWAYML_API_KEY. Задайте его в .env или переменных окружения!')
        sys.exit(1)
    # Безопасное логирование ключа
    print(f'[TEST] RUNWAYML_API_KEY (masked): {mask_key(key)}')
    # Предупреждение, если ключ слишком короткий или похож на тестовый
    if len(key) < 20:
        print('[TEST][WARNING] Ключ runwayml подозрительно короткий — проверьте его корректность!')
    # Комментарий: не храните ключ в открытом виде в коде или репозитории!
    # ВНИМАНИЕ: Никогда не размещайте секретные ключи в открытом виде в коде или публичных репозиториях!

def check_result():
    """Проверяет результат обработки задачи"""
    result_file = RESULTS_DIR / f'{TEST_TASK_ID}_runwayml.json'
    if not result_file.exists():
        print('[TEST][ERROR] Файл результата не найден!')
        return False
    
    with open(result_file, 'r', encoding='utf-8') as f:
        result = json.load(f)
    
    print(f'[TEST] Результат задачи: {result}')
    
    if result['status'] != 'success':
        print(f'[TEST][ERROR] Статус задачи: {result["status"]}')
        print(f'[TEST][ERROR] Сообщение: {result.get("message", "Нет сообщения")}')
        if result.get('error'):
            print(f'[TEST][ERROR] Ошибка: {result["error"]}')
        return False
    
    # Проверяем наличие финального изображения
    output_image = result.get('output_image')
    if not output_image:
        print('[TEST][ERROR] Путь к финальному изображению не указан!')
        return False
    
    # Извлекаем имя файла из пути и проверяем существование
    out_img_name = output_image.replace('\\', '/').split('/')[-1]
    img_path = PROCESSED_DIR / out_img_name
    if not img_path.exists():
        print(f'[TEST][ERROR] Финальное изображение не найдено: {img_path}')
        print(f'[TEST][DEBUG] Содержимое папки processed:')
        for f in PROCESSED_DIR.iterdir():
            print(f'  {f.name}')
        return False
    
    print(f'[TEST] Финальное изображение найдено: {img_path}')
    return True

def main():
    print('--- Интеграционный тест удаления логотипа: только RunwayML ---')
    # Проверяем наличие ключа runwayml перед запуском теста
    check_runwayml_key()
    run_logo_removal_test('runwayml')
    # test_season_icon()  # временно не запускаем
    
    # Проверка результата
    if check_result():
        print("[TEST] Интеграционный тест успешно завершён!")
        # НЕ вызываем cleanup() здесь - оставляем файлы для проверки
    else:
        print("[TEST][ERROR] Интеграционный тест завершился с ошибкой!")
        sys.exit(1)

if __name__ == '__main__':
    main() 