import os
from PIL import Image

src_images_dir = 'downloads/all_tires'
out_file = 'all_tires_image_sizes.txt'

sizes = set()

for img_name in os.listdir(src_images_dir):
    if not img_name.lower().endswith(('.png', '.jpg', '.jpeg')):
        continue
    img_path = os.path.join(src_images_dir, img_name)
    try:
        with Image.open(img_path) as img:
            sizes.add(img.size)
    except Exception:
        continue

with open(out_file, 'w') as f:
    for size in sorted(sizes):
        f.write(f'{size}\n')

print(f'Найдено {len(sizes)} уникальных размеров. Список сохранён в {out_file}') 