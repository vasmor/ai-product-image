import os
from PIL import Image
import numpy as np

def img_hash(img_path):
    img = Image.open(img_path).convert('L').resize((64, 64))
    arr = np.array(img)
    return hash(arr.tobytes())

def mse(img1, img2):
    arr1 = np.array(Image.open(img1).convert('L').resize((64, 64)))
    arr2 = np.array(Image.open(img2).convert('L').resize((64, 64)))
    return np.mean((arr1 - arr2) ** 2)

inpaint_dir = 'C:/ai-product-image-project/lama-local/bin/outputs/2025-06-26/20-28-59/lama_eval_predicts'
val_dir = 'C:/ai-product-image-project/valid-dataset/val_images'

for fname in os.listdir(inpaint_dir):
    if not fname.endswith('_inpainted.png'):
        continue
    base = fname.replace('_inpainted.png', '')
    mask = os.path.join(val_dir, base + '_mask.png')
    orig = os.path.join(val_dir, base + '.jpg')
    inpaint = os.path.join(inpaint_dir, fname)
    if os.path.exists(mask):
        mse_mask = mse(inpaint, mask)
        if mse_mask < 1.0:
            print(f'{fname}: inpainted ≈ mask (MSE={mse_mask:.2f})')
    if os.path.exists(orig):
        mse_orig = mse(inpaint, orig)
        if mse_orig < 1.0:
            print(f'{fname}: inpainted ≈ original (MSE={mse_orig:.2f})') 