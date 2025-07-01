import os
import cv2
import numpy as np

SRC_DIR = 'reference_masks'
DST_DIR = 'reference_masks_256'
TARGET_SIZE = 256

os.makedirs(DST_DIR, exist_ok=True)

for fname in os.listdir(SRC_DIR):
    if not fname.lower().endswith('.png'):
        continue
    src_path = os.path.join(SRC_DIR, fname)
    mask = cv2.imread(src_path, cv2.IMREAD_GRAYSCALE)
    if mask is None:
        print(f'Не удалось открыть {src_path}')
        continue
    h, w = mask.shape
    # Определяем, квадрат или вертикальный прямоугольник
    if w == h:
        # Просто resize
        mask_resized = cv2.resize(mask, (TARGET_SIZE, TARGET_SIZE), interpolation=cv2.INTER_NEAREST)
    else:
        # Вертикальный прямоугольник
        scale = TARGET_SIZE / h
        new_h = TARGET_SIZE
        new_w = int(w * scale)
        mask_scaled = cv2.resize(mask, (new_w, new_h), interpolation=cv2.INTER_NEAREST)
        # Вставляем по центру на чёрный квадрат
        mask_resized = np.zeros((TARGET_SIZE, TARGET_SIZE), dtype=np.uint8)
        x_offset = (TARGET_SIZE - new_w) // 2
        mask_resized[:, x_offset:x_offset+new_w] = mask_scaled
    dst_path = os.path.join(DST_DIR, fname)
    cv2.imwrite(dst_path, mask_resized)
    print(f'Сохранено: {dst_path}')

print('Готово!') 