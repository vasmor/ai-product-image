import os
from PIL import Image
import numpy as np

# Путь к папке с изображениями и масками
val_dir = 'C:/ai-product-image-project/valid-dataset/val_images'

# Выбираем первую попавшуюся пару
for fname in os.listdir(val_dir):
    if fname.endswith('.jpg') and not fname.endswith('_mask.jpg'):
        base = fname[:-4]
        img_path = os.path.join(val_dir, fname)
        mask_path = os.path.join(val_dir, base + '_mask.png')
        if os.path.exists(mask_path):
            img = np.array(Image.open(img_path).convert('RGB'))
            mask = np.array(Image.open(mask_path).convert('L'))
            mask_bin = (mask > 127).astype(np.uint8)
            masked_img = img.copy()
            masked_img[mask_bin == 1] = 0
            Image.fromarray(img).save('input_image.png')
            Image.fromarray(mask).save('input_mask.png')
            Image.fromarray(masked_img).save('input_masked_img.png')
            print('Сохранены input_image.png, input_mask.png, input_masked_img.png')
            break 