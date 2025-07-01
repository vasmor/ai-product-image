import torch
import torch.nn.functional as F
import numpy as np
from PIL import Image
from pathlib import Path

# --- Минимальный модуль U2NETPredictor ---
# Скачайте веса u2net.pth отсюда: https://github.com/xuebinqin/U-2-Net/releases
# Поместите файл u2net.pth рядом с этим модулем или укажите путь явно

class U2NETPredictor:
    def __init__(self, weights_path=None, device=None):
        print(f'[U2NETPredictor] __file__ = {__file__}')
        if weights_path is None:
            weights_path = (Path(__file__).parent / 'u2net.pth').resolve()
        else:
            weights_path = Path(weights_path).resolve()
        print(f'[U2NETPredictor] type(weights_path): {type(weights_path)}')
        print(f'[U2NETPredictor] Проверка наличия весов по пути: {weights_path}')
        print(f'[U2NETPredictor] Файл существует: {weights_path.exists()}')
        print(f'[U2NETPredictor] Содержимое папки: {list(weights_path.parent.glob("*"))}')
        self.device = device or ('cuda' if torch.cuda.is_available() else 'cpu')
        if not weights_path.exists():
            print(f'[U2NETPredictor] ОШИБКА: файл не найден по пути: {weights_path}')
            raise FileNotFoundError(f'Весовой файл не найден: {weights_path}\nСкачайте с https://github.com/xuebinqin/U-2-Net/releases')
        self.model = self._load_model(weights_path)
        self.model.eval()

    def _load_model(self, weights_path):
        from u2net_arch import U2NET  # абсолютный импорт
        net = U2NET(3, 1)
        net.load_state_dict(torch.load(weights_path, map_location=self.device))
        net.to(self.device)
        return net

    def predict(self, pil_img):
        # pil_img: PIL.Image (RGB)
        img = pil_img.convert('RGB').resize((320, 320))
        img_np = np.array(img).astype(np.float32) / 255.0
        img_np = img_np.transpose((2, 0, 1))[None, ...]
        img_tensor = torch.from_numpy(img_np).to(self.device)
        with torch.no_grad():
            d1, *_ = self.model(img_tensor)
            pred = d1[:, 0, :, :]
            pred = F.upsample(pred.unsqueeze(0), size=pil_img.size[::-1], mode='bilinear', align_corners=False)
            pred = pred.squeeze().cpu().numpy()
            pred = (pred - pred.min()) / (pred.max() - pred.min() + 1e-8)
            mask = (pred * 255).astype(np.uint8)
        return mask

# ---
# Для работы требуется файл u2net_arch.py (архитектура модели) из оф. репозитория U-2-Net:
# https://github.com/xuebinqin/U-2-Net/blob/master/u2net_test.py
# https://github.com/xuebinqin/U-2-Net/blob/master/model/u2net.py
# Поместите u2net_arch.py рядом с этим файлом. 