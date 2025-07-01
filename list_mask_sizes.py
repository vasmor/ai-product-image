import os
from PIL import Image

masks_dir = 'reference_masks'
out_file = 'reference_mask_sizes.txt'

sizes = set()
ratios = set()

for mask_name in os.listdir(masks_dir):
    if not mask_name.lower().endswith(('.png', '.jpg', '.jpeg')):
        continue
    mask_path = os.path.join(masks_dir, mask_name)
    try:
        with Image.open(mask_path) as img:
            size = img.size
            sizes.add(size)
            # Вычисляем соотношение сторон как float с округлением
            ratio = round(size[0] / size[1], 4) if size[1] != 0 else 0
            ratios.add(ratio)
    except Exception:
        continue

with open(out_file, 'w') as f:
    f.write('Уникальные размеры масок:\n')
    for size in sorted(sizes):
        f.write(f'{size}\n')
    f.write('\nУникальные соотношения сторон (width/height):\n')
    for ratio in sorted(ratios):
        f.write(f'{ratio}\n')

print(f'Найдено {len(sizes)} уникальных размеров и {len(ratios)} уникальных соотношений сторон. Список сохранён в {out_file}') 