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

for mask_name in os.listdir(ref_masks_dir):
    mask_path = os.path.join(ref_masks_dir, mask_name)
    with Image.open(mask_path) as mask_img:
        mask_size = mask_img.size  # (width, height)

    # Найти все изображения такого же размера
    matching_images = []
    for img_name in os.listdir(src_images_dir):
        img_path = os.path.join(src_images_dir, img_name)
        try:
            with Image.open(img_path) as img:
                if img.size == mask_size:
                    matching_images.append(img_name)
        except Exception:
            continue

    # Случайно выбрать N изображений
    selected_images = random.sample(matching_images, min(N, len(matching_images)))

    for img_name in selected_images:
        # Копируем изображение
        shutil.copy(os.path.join(src_images_dir, img_name), os.path.join(val_images_dir, img_name))
        # Копируем маску под тем же именем
        shutil.copy(mask_path, os.path.join(val_masks_dir, img_name))

    print(f'Для маски {mask_name} найдено {len(matching_images)} совпадающих изображений, выбрано {len(selected_images)}.')

print('Готово! Проверьте папки valid-dataset/val_images и valid-dataset/val_masks.') 