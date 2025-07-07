import os
import shutil
import argparse
from pathlib import Path
import time

# Папки для очистки
CLEANUP_DIRS = [
    '../uploads/ai_image/temp',
    '../uploads/ai_image/archive',
    '../uploads/ai_image/logs',
    # '../uploads/ai_image/processed',  # если нужно
    # '../uploads/ai_image/results',    # если нужно
]
DAYS_TO_KEEP = 7  # Сколько дней хранить файлы

def cleanup_test_files(processed_dir):
    count = 0
    for f in Path(processed_dir).glob('test_*'):
        f.unlink()
        count += 1
    for f in Path(processed_dir).glob('*_debug*'):
        f.unlink()
        count += 1
    print(f'[CLEANUP] Удалено тестовых файлов: {count}')

def cleanup_old_logs(logs_dir, keep_lines=100):
    for log_file in Path(logs_dir).glob('*.log'):
        with open(log_file, 'r', encoding='utf-8') as f:
            lines = f.readlines()
        if len(lines) > keep_lines:
            with open(log_file, 'w', encoding='utf-8') as f:
                f.writelines(lines[-keep_lines:])
            print(f'[CLEANUP] Обрезан лог {log_file} до {keep_lines} строк')

def cleanup_temp_dirs(temp_dir, archive_dir):
    for d in [temp_dir, archive_dir]:
        d = Path(d)
        if d.exists():
            shutil.rmtree(d)
            d.mkdir(parents=True, exist_ok=True)
            print(f'[CLEANUP] Очищена папка {d}')

def cleanup_tasks_and_results(tasks_dir, results_dir, days=7):
    now = time.time()
    for d in [tasks_dir, results_dir]:
        for f in Path(d).glob('*.json'):
            if now - f.stat().st_mtime > days * 86400:
                f.unlink()
                print(f'[CLEANUP] Удалён старый файл: {f}')

def cleanup_dir(path, days):
    now = time.time()
    for fname in os.listdir(path):
        fpath = os.path.join(path, fname)
        if os.path.isfile(fpath):
            if now - os.path.getmtime(fpath) > days * 86400:
                try:
                    os.remove(fpath)
                    print(f"Удалён файл: {fpath}")
                except Exception as e:
                    print(f"Ошибка при удалении {fpath}: {e}")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--processed', type=str, required=True)
    parser.add_argument('--logs', type=str, required=True)
    parser.add_argument('--temp', type=str, required=True)
    parser.add_argument('--archive', type=str, required=True)
    parser.add_argument('--tasks', type=str, required=True)
    parser.add_argument('--results', type=str, required=True)
    parser.add_argument('--all', action='store_true', help='Выполнить все виды очистки')
    args = parser.parse_args()
    if args.all or args.processed:
        cleanup_test_files(args.processed)
    if args.all or args.logs:
        cleanup_old_logs(args.logs)
    if args.all or args.temp or args.archive:
        cleanup_temp_dirs(args.temp, args.archive)
    if args.all or args.tasks or args.results:
        cleanup_tasks_and_results(args.tasks, args.results)

if __name__ == '__main__':
    for d in CLEANUP_DIRS:
        if os.path.isdir(d):
            cleanup_dir(d, DAYS_TO_KEEP)
    main() 