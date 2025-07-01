import os
import glob
import numpy as np
from PIL import Image

IMAGES_DIR = r'C:/ai-product-image-project/dataset-mask/images'
LABELS_DIR = r'C:/ai-product-image-project/dataset-mask/labels'
OUT_MASKS_DIR = r'C:/ai-product-image-project/reference_masks/new'

os.makedirs(OUT_MASKS_DIR, exist_ok=True)

THRESH = 5  # Порог для объединения масок в один тип (в пикселях)

mask_types = []  # [ (dx, dy, w, h, [(dx, dy, w, h, img_name, img_size)]) ]

def find_mask_type(dx, dy, w, h):
    for idx, (dx0, dy0, w0, h0, samples) in enumerate(mask_types):
        if (abs(dx - dx0) <= THRESH and abs(dy - dy0) <= THRESH and
            abs(w - w0) <= THRESH and abs(h - h0) <= THRESH):
            # Обновляем максимальные размеры
            mask_types[idx][2] = max(w, w0)
            mask_types[idx][3] = max(h, h0)
            return idx
    mask_types.append([dx, dy, w, h, []])
    return len(mask_types) - 1

for label_path in glob.glob(os.path.join(LABELS_DIR, '*.txt')):
    base = os.path.splitext(os.path.basename(label_path))[0]
    img_path = os.path.join(IMAGES_DIR, base + '.jpg')
    if not os.path.exists(img_path):
        img_path = os.path.join(IMAGES_DIR, base + '.png')
        if not os.path.exists(img_path):
            print(f'Image not found for {label_path}')
            continue
    img = Image.open(img_path)
    W, H = img.size
    x_img_c = W // 2
    y_img_c = H // 2

    with open(label_path, 'r') as f:
        for line in f:
            parts = line.strip().split()
            if len(parts) != 5:
                continue
            _, x_c, y_c, w, h = map(float, parts)
            x_c_px = round(x_c * W)
            y_c_px = round(y_c * H)
            w_px = round(w * W)
            h_px = round(h * H)
            dx = x_c_px - x_img_c
            dy = y_c_px - y_img_c
            idx = find_mask_type(dx, dy, w_px, h_px)
            mask_types[idx][4].append((dx, dy, w_px, h_px, base, (W, H)))

print(f'Найдено уникальных типов масок: {len(mask_types)}')
for i, (dx, dy, w, h, samples) in enumerate(mask_types, 1):
    print(f"Тип {i}: dX={dx}, dY={dy}, W={w}, H={h}, примеров: {len(samples)}")
    # Визуализация маски для первого подходящего размера изображения
    if samples:
        _, _, _, _, _, (W, H) = samples[0]
        mask = np.zeros((H, W), dtype=np.uint8)
        x_img_c = W // 2
        y_img_c = H // 2
        xc = x_img_c + dx
        yc = y_img_c + dy
        x1 = max(0, int(round(xc - w / 2)))
        y1 = max(0, int(round(yc - h / 2)))
        x2 = min(W, int(round(xc + w / 2)))
        y2 = min(H, int(round(yc + h / 2)))
        mask[y1:y2, x1:x2] = 255
        out_path = os.path.join(OUT_MASKS_DIR, f'ref_mask_{i}.png')
        Image.fromarray(mask).save(out_path)
        print(f'  Маска сохранена: {out_path}') 