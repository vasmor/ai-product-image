import os
from PIL import Image, ImageDraw

# Пути (от корня рабочего пространства)
images_dir = 'downloads/lama_dataset/images'
labels_dir = 'dataset/train/labels'
masks_dir = 'downloads/lama_dataset/masks'

os.makedirs(masks_dir, exist_ok=True)

for img_name in os.listdir(images_dir):
    if not img_name.lower().endswith(('.png', '.jpg', '.jpeg')):
        continue
    img_path = os.path.join(images_dir, img_name)
    im = Image.open(img_path)
    w, h = im.size

    label_name = os.path.splitext(img_name)[0] + '.txt'
    label_path = os.path.join(labels_dir, label_name)
    mask = Image.new('L', (w, h), 0)
    draw = ImageDraw.Draw(mask)

    if os.path.exists(label_path):
        with open(label_path, 'r') as f:
            for line in f:
                parts = line.strip().split()
                if len(parts) < 5:
                    continue
                cls, xc, yc, bw, bh = map(float, parts[:5])
                # Только один класс размечен, фильтрация не требуется
                xc, yc, bw, bh = xc * w, yc * h, bw * w, bh * h
                x1 = int(xc - bw / 2)
                y1 = int(yc - bh / 2)
                x2 = int(xc + bw / 2)
                y2 = int(yc + bh / 2)
                draw.rectangle([x1, y1, x2, y2], fill=255)

    mask.save(os.path.join(masks_dir, img_name.replace('.jpg', '.png').replace('.jpeg', '.png')))

print('Готово! Новые маски созданы в', masks_dir) 