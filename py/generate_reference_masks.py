import os
import glob
import cv2
import numpy as np
from sklearn.cluster import KMeans

# Параметры
LABEL_DIRS = [
    'dataset/train/labels',
    'dataset/valid/labels',
    'dataset/test/labels',
]
IMAGE_DIRS = [
    'dataset/train/images',
    'dataset/valid/images',
    'dataset/test/images',
]
MASKS_OUT_DIR = 'reference_masks'
MIN_CLUSTERS = 3  # минимальное число кластеров
MAX_CLUSTERS = 10  # максимальное (чтобы не зациклиться)
ELBOW_THRESHOLD = 0.10  # относительное уменьшение inertia (10%)
IMG_EXTS = ['.jpg', '.jpeg', '.png']

os.makedirs(MASKS_OUT_DIR, exist_ok=True)

# Сбор bbox из разметки
def find_image(label_path, image_dirs):
    base = os.path.splitext(os.path.basename(label_path))[0]
    for d in image_dirs:
        for ext in IMG_EXTS:
            img_path = os.path.join(d, base + ext)
            if os.path.exists(img_path):
                return img_path
    return None

def parse_yolo_label(label_path):
    bboxes = []
    with open(label_path, 'r') as f:
        for line in f:
            parts = line.strip().split()
            if len(parts) != 5:
                continue
            _, x, y, w, h = map(float, parts)
            bboxes.append((x, y, w, h))
    return bboxes

all_bbox = []
all_img_shapes = []

for label_dir in LABEL_DIRS:
    for label_path in glob.glob(os.path.join(label_dir, '*.txt')):
        img_path = find_image(label_path, IMAGE_DIRS)
        if img_path is None:
            print(f'Не найдено изображение для {label_path}')
            continue
        img = cv2.imread(img_path)
        if img is None:
            print(f'Не удалось открыть {img_path}')
            continue
        h, w = img.shape[:2]
        bboxes = parse_yolo_label(label_path)
        for x, y, bw, bh in bboxes:
            # YOLO: x, y, w, h — относительные (центр, ширина, высота)
            abs_x = x * w
            abs_y = y * h
            abs_bw = bw * w
            abs_bh = bh * h
            # Для кластеризации: (центр_x, центр_y, ширина, высота)
            all_bbox.append([abs_x, abs_y, abs_bw, abs_bh])
            all_img_shapes.append((w, h))

if not all_bbox:
    print('Не найдено bbox!')
    exit(1)

X = np.array(all_bbox)
print(f'Всего bbox: {len(X)}')

# --- Автоматический подбор числа кластеров ---
prev_inertia = None
best_n = MIN_CLUSTERS
for n_clusters in range(MIN_CLUSTERS, MAX_CLUSTERS + 1):
    kmeans = KMeans(n_clusters=n_clusters, random_state=42, n_init=10)
    kmeans.fit(X)
    inertia = kmeans.inertia_
    print(f'Кластеры: {n_clusters}, inertia: {inertia:.2f}')
    if prev_inertia is not None:
        rel_decrease = (prev_inertia - inertia) / prev_inertia
        print(f'  Относительное уменьшение inertia: {rel_decrease:.3f}')
        if rel_decrease < ELBOW_THRESHOLD:
            break
        best_n = n_clusters
    prev_inertia = inertia

# Итоговая кластеризация
print(f'Оптимальное число кластеров: {best_n}')
kmeans = KMeans(n_clusters=best_n, random_state=42, n_init=10)
labels = kmeans.fit_predict(X)
centers = kmeans.cluster_centers_

# Генерация эталонных масок
# Для каждого кластера — средний размер изображения (или задать руками)
for i, (cx, cy, bw, bh) in enumerate(centers):
    # Средний размер изображения для этого кластера
    idxs = np.where(labels == i)[0]
    ws = [all_img_shapes[j][0] for j in idxs]
    hs = [all_img_shapes[j][1] for j in idxs]
    if ws and hs:
        mean_w = int(np.mean(ws))
        mean_h = int(np.mean(hs))
    else:
        mean_w, mean_h = 512, 512
    mask = np.zeros((mean_h, mean_w), dtype=np.uint8)
    # Переводим центр и размер bbox в координаты прямоугольника
    x1 = int(cx - bw / 2)
    y1 = int(cy - bh / 2)
    x2 = int(cx + bw / 2)
    y2 = int(cy + bh / 2)
    # Ограничиваем границы
    x1 = max(0, x1)
    y1 = max(0, y1)
    x2 = min(mean_w - 1, x2)
    y2 = min(mean_h - 1, y2)
    cv2.rectangle(mask, (x1, y1), (x2, y2), 255, -1)
    out_path = os.path.join(MASKS_OUT_DIR, f'ref_mask_{i+1}.png')
    cv2.imwrite(out_path, mask)
    print(f'Сохранена эталонная маска: {out_path}')

print('Готово!') 