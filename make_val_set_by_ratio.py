import os
from PIL import Image
import shutil
import random

# Пути
src_images_dir = 'downloads/all_tires'
ref_masks_dir = 'reference_masks'
val_images_dir = 'valid-dataset/val_images'
val_masks_dir = 'valid-dataset/val_masks'

os.makedirs(val_images_dir, exist_ok=True)
os.makedirs(val_masks_dir, exist_ok=True)

# Сколько изображений брать на одну маску
N = 5
# Точность сравнения соотношения сторон
RATIO_EPS = 0.01

def get_ratio(size):
    w, h = size
    return round(w / h, 3) if h != 0 else 0

def center_crop_and_resize(img, target_size):
    # Центр-кроп до нужного соотношения, затем ресайз
    target_w, target_h = target_size
    src_w, src_h = img.size
    src_ratio = src_w / src_h
    target_ratio = target_w / target_h
    # Кроп
    if src_ratio > target_ratio:
        # Обрезаем по ширине
        new_w = int(target_ratio * src_h)
        left = (src_w - new_w) // 2
        img = img.crop((left, 0, left + new_w, src_h))
    elif src_ratio < target_ratio:
        # Обрезаем по высоте
        new_h = int(src_w / target_ratio)
        top = (src_h - new_h) // 2
        img = img.crop((0, top, src_w, top + new_h))
    # Ресайз
    return img.resize(target_size, Image.LANCZOS)

for mask_name in os.listdir(ref_masks_dir):
    mask_path = os.path.join(ref_masks_dir, mask_name)
    with Image.open(mask_path) as mask_img:
        mask_size = mask_img.size
        mask_ratio = get_ratio(mask_size)

    # Найти все изображения с похожим соотношением сторон
    matching_images = []
    for img_name in os.listdir(src_images_dir):
        img_path = os.path.join(src_images_dir, img_name)
        try:
            with Image.open(img_path) as img:
                img_size = img.size
                img_ratio = get_ratio(img_size)
                if abs(img_ratio - mask_ratio) < RATIO_EPS:
                    matching_images.append((img_name, img_size))
        except Exception:
            continue

    selected_images = random.sample(matching_images, min(N, len(matching_images)))

    for img_name, img_size in selected_images:
        # Определяем минимальный размер
        target_w = min(mask_size[0], img_size[0])
        target_h = min(mask_size[1], img_size[1])
        target_size = (target_w, target_h)
        # Копируем и ресайзим изображение
        with Image.open(os.path.join(src_images_dir, img_name)) as img:
            img_out = center_crop_and_resize(img, target_size)
            img_out.save(os.path.join(val_images_dir, img_name))
        # Копируем и ресайзим маску под тем же именем
        with Image.open(mask_path) as mask_img:
            mask_out = center_crop_and_resize(mask_img, target_size)
            mask_out.save(os.path.join(val_masks_dir, img_name))

    print(f'Для маски {mask_name} найдено {len(matching_images)} совпадающих по пропорции изображений, выбрано {len(selected_images)}.')

print('Готово! Проверьте папки valid-dataset/val_images и valid-dataset/val_masks.') 