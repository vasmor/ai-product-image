import sys
from pathlib import Path
from PIL import ImageFont

if len(sys.argv) < 3:
    print("Использование: python font_text_size_tester.py <размер_шрифта> <текст>")
    sys.exit(1)

font_size = int(sys.argv[1])
text = ' '.join(sys.argv[2:])

fonts_dir = Path('uploads/ai_image/fonts')
font_files = list(fonts_dir.glob('*.ttf')) + list(fonts_dir.glob('*.otf'))

if not font_files:
    print(f"В папке {fonts_dir} не найдено ни одного .ttf или .otf файла!")
    sys.exit(1)

for font_path in font_files:
    print(f"\n=== Шрифт: {font_path.name} ===")
    try:
        font = ImageFont.truetype(str(font_path), font_size)
    except Exception as e:
        print(f"Ошибка загрузки шрифта: {e}")
        continue
    # getbbox
    try:
        bbox = font.getbbox(text)
        bbox_w = bbox[2] - bbox[0]
        bbox_h = bbox[3] - bbox[1]
        print(f"getbbox: {bbox}, width={bbox_w}, height={bbox_h}")
    except Exception as e:
        print(f"getbbox не поддерживается: {e}")
    # getmask
    try:
        mask = font.getmask(text)
        mask_w, mask_h = mask.size
        print(f"getmask.size: width={mask_w}, height={mask_h}")
    except Exception as e:
        print(f"getmask не поддерживается: {e}")
    # getlength (ширина в float)
    try:
        length = font.getlength(text)
        print(f"getlength: {length}")
    except Exception as e:
        print(f"getlength не поддерживается: {e}")
    # font metrics
    try:
        ascent, descent = font.getmetrics()
        print(f"getmetrics: ascent={ascent}, descent={descent}")
    except Exception as e:
        print(f"getmetrics не поддерживается: {e}") 