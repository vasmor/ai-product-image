import os
from PIL import Image

folder = 'C:/ai-product-image-project/valid-dataset/val_images'

bad_pairs = []
all_pairs = 0
for fname in os.listdir(folder):
    if fname.endswith('.jpg') and not fname.endswith('_mask.jpg'):
        base = fname[:-4]
        mask_name = base + '_mask.png'
        mask_path = os.path.join(folder, mask_name)
        img_path = os.path.join(folder, fname)
        if os.path.exists(mask_path):
            with Image.open(img_path) as img, Image.open(mask_path) as mask:
                if img.size != mask.size:
                    bad_pairs.append((fname, mask_name, img.size, mask.size))
            all_pairs += 1

print(f'Всего пар: {all_pairs}')
if bad_pairs:
    print('Несовпадающие пары:')
    for img, mask, sz1, sz2 in bad_pairs:
        print(f'{img} <-> {mask}: {sz1} vs {sz2}')
else:
    print('Все размеры совпадают!') 